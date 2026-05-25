<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Waffle\Commons\Cache\Factory\CacheFactory;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant as CacheConstant;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\EventDispatcher\EventListenerInterface;
use Waffle\Commons\Contracts\Handler\ArgumentResolverInterface;
use Waffle\Commons\Contracts\Security\Csrf\Constant as CsrfConstant;
use Waffle\Commons\Contracts\Security\Csrf\CsrfTokenManagerInterface;
use Waffle\Commons\Contracts\Service\ReflectionServiceInterface;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Handler\ControllerArgumentResolver;
use Waffle\Handler\ControllerDispatcher;
use Waffle\Service\ReflectionService;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\EventDispatcher\Dispatcher\EventDispatcher;
use Waffle\Commons\EventDispatcher\Provider\ListenerProvider;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Log\Channel\LogChannel;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Pipeline\Middleware\SecureHeadersMiddleware;
use Waffle\Commons\Pipeline\Middleware\TrustedHostMiddleware;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Csrf\CsrfTokenManager;
use Waffle\Commons\Security\Middleware\AnonymousSessionMiddleware;
use Waffle\Commons\Security\Middleware\CsrfMiddleware;
use Waffle\Commons\Security\Middleware\SecurityMiddleware;
use Waffle\Commons\Security\Security;
use Workspace\Kernel;

/**
 * The Glue Code: Assembles the application components.
 */
final class AppKernelFactory
{
    /**
     * Creates the fully assembled Kernel.
     */
    public static function create(string $env = Constant::ENV_PROD, bool $debug = false): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;
        $rootConfig = $root . DIRECTORY_SEPARATOR . APP_CONFIG;

        // 1. Instantiate the concrete Container (from waffle-commons/container)
        $container = new Container();

        // Register PSR-17 Factory (Required for Controllers AND ErrorHandler)
        $responseFactory = new ResponseFactory();
        if (class_exists(ResponseFactory::class)) {
            $container->set(ResponseFactoryInterface::class, $responseFactory);
        }

        // 2. Build the env registry from .env files + process env (Beta-1 hardening:
        //    DotEnv no longer mutates the global PHP environment, so we merge it
        //    with the live process env here. Process env wins on conflict so
        //    Docker/K8s-provided values still override .env defaults).
        $processEnv = getenv();
        $envRegistry = array_merge(new DotEnv($root)->load(), $processEnv);

        // 3. Instantiate the concrete Config (from waffle-commons/config)
        $config = new Config(configDir: $rootConfig, environment: $env, env: $envRegistry);
        // STAB-01 (Beta-1): no static GlobalsFactory — WaffleRuntime constructs
        // its own per-process instance. Trusted-host enforcement lives in
        // TrustedHostMiddleware (RFC-003 §3.2).
        $trustedHosts = $config->getArray(key: 'waffle.trusted_hosts');

        // SEC-01 (Beta-1, option C): stateless HMAC CSRF manager bound to a
        // per-browser anonymous SID. Secret comes from config (env-interpolated)
        // with a direct getenv() fallback; missing or short ⇒ boot abort in prod.
        $csrfTokenManager = new CsrfTokenManager(secret: self::resolveCsrfSecret($config, $env));
        $container->set(CsrfTokenManagerInterface::class, $csrfTokenManager);

        // 3. Instantiate Security (from waffle-commons/security)
        $security = new Security($config);

        // 4. Wrap the container with Security Decorator
        $secureContainer = new SecureContainer($container, $security);

        // 5. Instantiate the Pipeline Middleware
        $stack = new MiddlewareStack();

        // We create the renderer and the middleware.
        // It must be PREPENDED to catch errors from all subsequent middlewares (Routing, Security, Dispatcher).
        $errorRenderer = new JsonErrorRenderer($responseFactory, $debug);
        $errorLogger = new StreamLogger();
        $errorHandler = new ErrorHandlerMiddleware(renderer: $errorRenderer, logger: $errorLogger);

        $stack->prepend(middleware: $errorHandler);

        // 5a. Host header allowlist — fail-fast before routing/security/dispatch.
        // Canonical Beta-1 order: ErrorHandler → TrustedHost → AnonymousSession →
        // Routing → Csrf → Security → SecureHeaders → Dispatcher.
        $stack->add(middleware: new TrustedHostMiddleware($trustedHosts));

        // 5a-bis. Per-browser anonymous SID. Must run before Csrf so the SID
        // attribute is populated for the HMAC binding (SEC-01 option C).
        $stack->add(middleware: new AnonymousSessionMiddleware());

        // 5b. Event Dispatcher setup
        $listenerProvider = new ListenerProvider();
        $eventDispatcher = new EventDispatcher($listenerProvider);

        // Auto-discover listeners from EventListener directory
        $eventListenersPath = $config->getString('waffle.paths.event_listeners');
        if (is_dir($eventListenersPath)) {
            self::discoverAndRegisterListeners($listenerProvider, $eventListenersPath);
        }

        // 6. Instantiate the Kernel
        $kernelLogger = new StreamLogger(channel: LogChannel::CORE);
        $kernel = new Kernel(logger: $kernelLogger);
        $kernel->setEventDispatcher($eventDispatcher);

        // 6a. Leftover-purge §3: bootstrap-side wiring of the terminal-handler trio.
        // The kernel no longer instantiates these inline; the factory owns the
        // composition and the kernel just resolves from the container.
        $reflectionService = new ReflectionService();
        $argumentResolver = new ControllerArgumentResolver($container, $reflectionService);
        $controllerDispatcher = new ControllerDispatcher(
            container: $container,
            dispatcher: $eventDispatcher,
            argumentResolver: $argumentResolver,
        );
        $container->set(ReflectionServiceInterface::class, $reflectionService);
        $container->set(ArgumentResolverInterface::class, $argumentResolver);
        $container->set(RequestHandlerInterface::class, $controllerDispatcher);

        // 7. Instantiate the PSR-16 cache (RFC-013) and register it for downstream consumers.
        $cache = self::buildCache($root, $config);
        $container->set(CacheInterface::class, $cache);

        // 8. Instantiate and Boot Router
        $controllersPath = $config->getString(key: 'waffle.paths.controllers');
        if (is_string($controllersPath)) {
            // Instantiate Router with the shared PSR-16 cache
            $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath, $cache);
            $router->boot(container: $secureContainer);

            // Create the Bridge Middleware and add it to the Stack
            // This connects the Router to the Pipeline
            $routingMiddleware = new CoreRoutingMiddleware($router);
            // This connects the SecureMiddleware to the Pipeline
            $secureLogger = new StreamLogger(channel: LogChannel::SECURITY);
            $secureMiddleware = new SecurityMiddleware(secureContainer: $secureContainer, logger: $secureLogger);
            $stack->add(middleware: $routingMiddleware);
            // CsrfMiddleware must come after Routing (reads `_classname`/`_method`
            // to find #[RequiresCsrfToken]) and before Security.
            $stack->add(middleware: new CsrfMiddleware($csrfTokenManager));
            $stack->add(middleware: $secureMiddleware);

            // Secure headers middleware
            $stack->add(middleware: new SecureHeadersMiddleware());
        }

        // 9. Inject Dependencies
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
     * Resolves the CSRF signing secret with sane fallbacks. Config wins; otherwise
     * read the env directly. Production refuses to boot when the secret is missing
     * or shorter than `CsrfConstant::MIN_SECRET_BYTES`; non-prod environments accept
     * a one-shot random secret so dev/test still boot cleanly.
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
                'CSRF secret missing or shorter than %d bytes in production. '
                . 'Set "waffle.security.csrf.secret" or env %s.',
                CsrfConstant::MIN_SECRET_BYTES,
                CsrfConstant::SECRET_ENV_KEY,
            ));
        }

        // Dev/test fallback: ephemeral per-process secret. Tokens issued under it
        // will NOT survive a worker restart — that is fine for non-prod use.
        return random_bytes(CsrfConstant::MIN_SECRET_BYTES);
    }

    /**
     * Builds the PSR-16 cache adapter chosen by `waffle.cache.adapter`.
     *
     * Falls back to the in-memory ArrayCache when no adapter is configured.
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
     * Auto-discovers and registers event listeners from a directory.
     * Scans for classes implementing EventListenerInterface with #[AsEventListener] attributes.
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

            // Quick check: does this file contain AsEventListener attribute?
            if (!str_contains($content, 'AsEventListener')) {
                continue;
            }

            // Extract FQCN using token_get_all (same approach as ReflectionTrait)
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
     * Extracts the fully qualified class name from a PHP file using token_get_all.
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
