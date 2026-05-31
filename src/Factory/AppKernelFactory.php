<?php

declare(strict_types=1);

namespace App\Factory;

use App\Kernel;
use PDO;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Waffle\Commons\Cache\Factory\CacheFactory;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant as CacheConstant;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\EventDispatcher\EventListenerInterface;
use Waffle\Commons\Contracts\Handler\ArgumentResolverInterface;
use Waffle\Commons\Contracts\Security\Csrf\Constant as CsrfConstant;
use Waffle\Commons\Contracts\Security\Csrf\CsrfTokenManagerInterface;
use Waffle\Commons\Contracts\Service\ReflectionServiceInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Migration\MigrationRunner;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\EventDispatcher\Dispatcher\EventDispatcher;
use Waffle\Commons\EventDispatcher\Provider\ListenerProvider;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\Log\Channel\LogChannel;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\Middleware\SecureHeadersMiddleware;
use Waffle\Commons\Pipeline\Middleware\TrustedHostMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Csrf\CsrfTokenManager;
use Waffle\Commons\Security\Middleware\AnonymousSessionMiddleware;
use Waffle\Commons\Security\Middleware\CsrfMiddleware;
use Waffle\Commons\Security\Middleware\SecurityMiddleware;
use Waffle\Commons\Security\Security;
use Waffle\Handler\ControllerArgumentResolver;
use Waffle\Handler\ControllerDispatcher;
use Waffle\Service\ReflectionService;

/**
 * Code d'assemblage : monte les composants de l'application.
 */
final class AppKernelFactory
{
    /**
     * Construit le Kernel entièrement assemblé.
     */
    public static function create(string $env = Constant::ENV_PROD, bool $debug = false): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;
        $rootConfig = $root . DIRECTORY_SEPARATOR . APP_CONFIG;

        // 1. Instanciation du Container concret (paquet waffle-commons/container).
        $container = new Container();

        // Enregistrement de la factory PSR-17 (requise par les contrôleurs ET l'ErrorHandler).
        $responseFactory = new ResponseFactory();
        if (class_exists(ResponseFactory::class)) {
            $container->set(ResponseFactoryInterface::class, $responseFactory);
        }

        // Enregistrement de la factory PSR-17 (requise par le client HTTP).
        $streamFactory = new StreamFactory();
        $container->set(StreamFactoryInterface::class, $streamFactory);

        // Enregistrement du client HTTP PSR-18 (paquet waffle-commons/http-client).
        $httpClient = new Client($responseFactory, $streamFactory);
        $container->set(ClientInterface::class, $httpClient);

        // 2. Construction du registre d'environnement à partir des fichiers .env
        //    et de l'environnement processus (durcissement Beta-1 : DotEnv ne mute
        //    plus l'environnement PHP global ; on le fusionne ici avec l'env vivant
        //    du processus. L'env processus l'emporte en cas de conflit, ce qui
        //    permet aux valeurs Docker/K8s d'écraser les défauts du .env).
        $processEnv = getenv();
        $envRegistry = array_merge(new DotEnv($root)->load(), $processEnv);

        // 3. Instanciation de la Config concrète (paquet waffle-commons/config).
        $config = new Config(configDir: $rootConfig, environment: $env, env: $envRegistry);
        // STAB-01 (Beta-1) : plus de GlobalsFactory statique — WaffleRuntime construit
        // sa propre instance par processus. L'enforcement des hôtes de confiance vit
        // dans TrustedHostMiddleware (RFC-003 §3.2).
        $trustedHosts = $config->getArray(key: 'waffle.trusted_hosts');

        // SEC-01 (Beta-1, option C) : gestionnaire CSRF sans état, basé sur un HMAC
        // lié à un SID anonyme par navigateur. Le secret vient de la config (avec
        // interpolation d'env) ou, à défaut, d'un getenv() direct ; absent ou trop
        // court ⇒ avortement du boot en production.
        $csrfTokenManager = new CsrfTokenManager(secret: self::resolveCsrfSecret($config, $env));
        $container->set(CsrfTokenManagerInterface::class, $csrfTokenManager);

        // 3. Instanciation de Security (paquet waffle-commons/security).
        $security = new Security($config);

        // 4. Décoration du container par le SecureContainer.
        $secureContainer = new SecureContainer($container, $security);

        // 5. Instanciation des middlewares du pipeline.
        $stack = new MiddlewareStack();

        // On crée le renderer et le middleware d'erreur.
        // Il doit être PREPEND-é pour capter les erreurs de tous les middlewares
        // suivants (Routing, Security, Dispatcher).
        $errorRenderer = new JsonErrorRenderer($responseFactory, $debug);
        $errorLogger = new StreamLogger();
        $errorHandler = new ErrorHandlerMiddleware(renderer: $errorRenderer, logger: $errorLogger);

        $stack->prepend(middleware: $errorHandler);

        // 5a. Allow-list des hôtes — première barrière exécutable du pipeline,
        // alimentée par `waffle.trusted_hosts` (app.yaml). L'ErrorHandler reste
        // « prepend »-é au-dessus, à dessein : il transforme un rejet d'hôte
        // malformé en réponse JSON 400 propre plutôt qu'en erreur fatale.
        // Ordre canonique Beta-1 : ErrorHandler → TrustedHost → AnonymousSession →
        // Routing → Csrf → Security → SecureHeaders → Dispatcher.
        $stack->add(middleware: new TrustedHostMiddleware($trustedHosts));

        // 5a-bis. SID anonyme par navigateur. Doit s'exécuter avant Csrf pour
        // que l'attribut SID soit déjà alimenté lors du binding HMAC (SEC-01 option C).
        $stack->add(middleware: new AnonymousSessionMiddleware());

        // 5b. Mise en place du dispatcher d'événements.
        $listenerProvider = new ListenerProvider();
        $eventDispatcher = new EventDispatcher($listenerProvider);

        // Auto-découverte des listeners dans le dossier EventListener.
        $eventListenersPath = $config->getString('waffle.paths.event_listeners');
        if (is_dir($eventListenersPath)) {
            self::discoverAndRegisterListeners($listenerProvider, $eventListenersPath);
        }

        // 6. Instanciation du Kernel.
        $kernelLogger = new StreamLogger(channel: LogChannel::CORE);
        $kernel = new Kernel(logger: $kernelLogger);
        $kernel->setEventDispatcher($eventDispatcher);

        // 6a. Câblage découplé du trio terminal (Beta 2 — Phase 4).
        // La factory ne construit plus les services à la main : elle déclare des
        // définitions dans le Container, et AbstractKernel::handle() les résout
        // à la volée via le standard PSR-11 (Container::build() invoque chaque
        // Closure avec lui-même en argument, cf. container/src/Container.php).
        // Le dispatcher d'événements est capturé dans la closure pour rester
        // injecté sans dépendre d'un slot supplémentaire dans le conteneur.
        $container->set(ReflectionServiceInterface::class, new ReflectionService());
        $container->set(ArgumentResolverInterface::class, static function (ContainerInterface $c): ArgumentResolverInterface {
            /** @var ReflectionServiceInterface $reflection */
            $reflection = $c->get(ReflectionServiceInterface::class);

            return new ControllerArgumentResolver($c, $reflection);
        });
        $container->set(RequestHandlerInterface::class, static function (ContainerInterface $c) use (
            $eventDispatcher,
        ): RequestHandlerInterface {
            /** @var ArgumentResolverInterface $resolver */
            $resolver = $c->get(ArgumentResolverInterface::class);

            return new ControllerDispatcher(container: $c, dispatcher: $eventDispatcher, argumentResolver: $resolver);
        });

        // 7. Instanciation du cache PSR-16 (RFC-013) et enregistrement pour les consommateurs en aval.
        $cache = self::buildCache($root, $config);
        $container->set(CacheInterface::class, $cache);

        // 7a. Instanciation du pool de connexions (RFC-022) et enregistrement pour les consommateurs en aval.
        $connectionPool = self::buildConnectionPool($config);
        $container->set(ConnectionPoolInterface::class, $connectionPool);
        $container->set(PDOConnectionPool::class, $connectionPool);

        // 7b. Instanciation du migration runner (RFC-022) et enregistrement dans le conteneur.
        $migrationRunner = new MigrationRunner(pool: $connectionPool, config: $config);
        $container->set(MigrationRunnerInterface::class, $migrationRunner);
        $container->set(MigrationRunner::class, $migrationRunner);

        // 8. Instanciation et démarrage du Router.
        $controllersPath = $config->getString(key: 'waffle.paths.controllers');
        if (is_string($controllersPath)) {
            // Router instancié avec le cache PSR-16 partagé.
            $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath, $cache);
            $router->boot(container: $secureContainer);

            // Création du middleware de pont et ajout dans le stack.
            // Il relie le Router au pipeline.
            $routingMiddleware = new CoreRoutingMiddleware($router, $responseFactory);
            // Il relie le SecureMiddleware au pipeline.
            $secureLogger = new StreamLogger(channel: LogChannel::SECURITY);
            $secureMiddleware = new SecurityMiddleware(secureContainer: $secureContainer, logger: $secureLogger);
            $stack->add(middleware: $routingMiddleware);
            // Le CsrfMiddleware doit s'exécuter après Routing (il lit `_classname`
            // et `_method` pour repérer #[RequiresCsrfToken]) et avant Security.
            $stack->add(middleware: new CsrfMiddleware($csrfTokenManager));
            $stack->add(middleware: $secureMiddleware);

            // Middleware d'en-têtes sécurisés.
            $stack->add(middleware: new SecureHeadersMiddleware());
        }

        // 9. Injection des dépendances.
        if (method_exists($kernel, 'setConfiguration')) {
            $kernel->setConfiguration($config);
        }

        if (method_exists($kernel, 'setSecurity')) {
            $kernel->setSecurity($security);
        }

        if (method_exists($kernel, 'setContainerImplementation')) {
            $kernel->setContainerImplementation($secureContainer);
        }

        if (method_exists($kernel, 'setMiddlewareStack')) {
            $kernel->setMiddlewareStack($stack);
        }

        return $kernel;
    }

    /**
     * Résout le secret de signature CSRF avec des fallbacks raisonnables. La
     * config gagne ; sinon on lit directement l'environnement. La production
     * refuse de démarrer si le secret est absent ou plus court que
     * `CsrfConstant::MIN_SECRET_BYTES` ; en hors-prod, un secret aléatoire
     * éphémère permet à dev/test de démarrer proprement.
     */
    private static function resolveCsrfSecret(Config $config, string $env): string
    {
        $fromConfig = $config->getString('waffle.security.csrf.secret');
        $candidate = is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;

        if ($candidate === null) {
            $fromEnv = getenv(CsrfConstant::SECRET_ENV_KEY);
            if (is_string($fromEnv) && $fromEnv !== '') {
                $candidate = $fromEnv;
            }
        }

        if ($candidate !== null && strlen($candidate) >= CsrfConstant::MIN_SECRET_BYTES) {
            return $candidate;
        }

        if ($env === Constant::ENV_PROD) {
            throw new RuntimeException(sprintf(
                'Secret CSRF manquant ou plus court que %d octets en production. '
                . 'Renseignez "waffle.security.csrf.secret" ou la variable d\'environnement %s.',
                CsrfConstant::MIN_SECRET_BYTES,
                CsrfConstant::SECRET_ENV_KEY,
            ));
        }

        // Fallback dev/test : secret éphémère par processus. Les jetons émis sous
        // ce secret ne survivront PAS au redémarrage d'un worker — ce qui est
        // acceptable hors production.
        return random_bytes(CsrfConstant::MIN_SECRET_BYTES);
    }

    /**
     * Construit l'adaptateur de cache PSR-16 choisi par `waffle.cache.adapter`.
     *
     * Retombe sur le ArrayCache en mémoire si aucun adaptateur n'est configuré.
     */
    public static function buildCache(string $root, Config $config): CacheInterface
    {
        $adapter = $config->getString('waffle.cache.adapter') ?? CacheConstant::BACKEND_ARRAY;
        $directory = $config->getString('waffle.cache.directory') ?? 'var/cache/psr16';
        $options = [
            'directory' => $root . DIRECTORY_SEPARATOR . $directory,
            'dsn' => $config->getString('waffle.cache.redis_dsn'),
            'default_ttl' => $config->getInt('waffle.cache.default_ttl'),
            'prefix' => $config->getString('waffle.cache.prefix'),
        ];

        return new CacheFactory()->create($adapter, $options);
    }

    /**
     * Construit le pool de connexions PDO (RFC-022) à partir de `waffle.database.*`.
     *
     * La fabrique injectée n'ouvre une connexion que lorsque le pool en a besoin :
     * en mode worker FrankenPHP, les sockets restent tièdes entre les requêtes,
     * sont sondés (« ping-before-dispense ») puis reconnectés de façon transparente.
     */
    public static function buildConnectionPool(Config $config): PDOConnectionPool
    {
        $driver = $config->getString('waffle.database.driver') ?? 'mysql';
        $host = $config->getString('waffle.database.host') ?? '127.0.0.1';
        $port = $config->getString('waffle.database.port') ?? '3306';
        $database = $config->getString('waffle.database.database') ?? '';
        $username = $config->getString('waffle.database.username') ?? 'root';
        $password = $config->getString('waffle.database.password') ?? '';
        $charset = $config->getString('waffle.database.charset') ?? 'utf8mb4';

        $dsn = sprintf('%s:host=%s;port=%s;dbname=%s;charset=%s', $driver, $host, $port, $database, $charset);

        // Fabrique sans état, rejouée à chaque création de connexion par le pool.
        return new PDOConnectionPool(
            factory: static fn(): PDO => new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]),
        );
    }

    /**
     * Auto-découvre et enregistre les listeners d'événements depuis un dossier.
     * Scanne les classes implémentant EventListenerInterface portant l'attribut
     * #[AsEventListener].
     */
    private static function discoverAndRegisterListeners(ListenerProvider $provider, string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $directory,
            \RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Vérification rapide : le fichier contient-il l'attribut AsEventListener ?
            if (!str_contains($content, 'AsEventListener')) {
                continue;
            }

            // Extraction du FQCN via token_get_all (même approche que ReflectionTrait).
            $fqcn = self::extractClassName($file->getPathname());
            if ($fqcn === '' || !class_exists($fqcn)) {
                continue;
            }

            $instance = new $fqcn();

            if ($instance instanceof EventListenerInterface) {
                $provider->register($instance);
            }
        }
    }

    /**
     * Extrait le nom de classe pleinement qualifié d'un fichier PHP via
     * token_get_all.
     */
    private static function extractClassName(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return '';
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $class = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                while (++$i < $count) {
                    if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                        break;
                    }
                    if (is_array($tokens[$i])) {
                        $namespace .= $tokens[$i][1];
                    }
                }
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $j = $i;
                while (++$j < $count) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break 2;
                    }
                    if ($tokens[$j] === '{') {
                        break;
                    }
                }
            }
        }

        if ($class === '') {
            return '';
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }
}
