<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\AppKernelFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use RuntimeException;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;

/**
 * FIX-01 #9 (audit Beta6) : régression du garde-fou fail-closed
 * `APP_ENV=prod` + `APP_DEBUG=true`, ajouté dans AppKernelFactory::create().
 *
 * `tests/config/app.yaml` (cf. tests/bootstrap.php) laisse APP_SECRET et les
 * secrets CSRF/Auth vides — hors production, SecretFactory::csrf()/auth()
 * retombent sur un secret éphémère. En production, ils redeviennent stricts : on fournit
 * donc explicitement des secrets valides (>= 32 octets) via putenv() pour isoler
 * le comportement testé ici (le garde-fou debug/prod) de ces vérifications
 * voisines, et on les restaure dans tearDown() pour ne pas polluer les autres
 * tests du même process PHPUnit.
 */
final class AppKernelFactoryTest extends AbstractTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        putenv('APP_SECRET');
        putenv('WAFFLE_CSRF_SECRET');
        putenv('WAFFLE_AUTH_SECRET');

        parent::tearDown();
    }

    /**
     * Génère un secret éphémère (>= 32 octets) pour isoler ce test des
     * vérifications SecretFactory::csrf()/auth() — jamais un
     * littéral en dur (mago `no-literal-password`), jamais réutilisé hors de
     * ce process de test.
     */
    private static function ephemeralSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    #[Test]
    public function create_throws_when_prod_and_debug_coincide(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_DEBUG/');

        AppKernelFactory::create(env: Constant::ENV_PROD, debug: true);
    }

    #[Test]
    public function create_boots_when_prod_and_debug_is_false(): void
    {
        putenv('WAFFLE_CSRF_SECRET=' . self::ephemeralSecret());
        putenv('WAFFLE_AUTH_SECRET=' . self::ephemeralSecret());

        $kernel = AppKernelFactory::create(env: Constant::ENV_PROD, debug: false);

        self::assertInstanceOf(KernelInterface::class, $kernel);
    }

    #[Test]
    public function create_boots_when_non_prod_and_debug_is_true(): void
    {
        $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: true);

        self::assertInstanceOf(KernelInterface::class, $kernel);
    }

    /**
     * Régression voisine (même famille fail-closed que #9) : `tests/config/app.yaml`
     * laisse `waffle.secret` et `waffle.security.csrf.secret` vides, et ni APP_SECRET
     * ni WAFFLE_CSRF_SECRET n'est exporté — SecretFactory::csrf() doit donc refuser
     * de démarrer en production plutôt que de retomber sur un secret éphémère.
     */
    #[Test]
    public function create_throws_when_prod_csrf_secret_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CSRF/');

        AppKernelFactory::create(env: Constant::ENV_PROD, debug: false);
    }

    /**
     * Même discipline pour SecretFactory::auth() (RFC-021) : un CSRF secret valide
     * seul ne suffit pas à démarrer en production si le secret du pont
     * d'authentification est manquant.
     */
    #[Test]
    public function create_throws_when_prod_auth_secret_is_missing(): void
    {
        putenv('WAFFLE_CSRF_SECRET=' . self::ephemeralSecret());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentification/');

        AppKernelFactory::create(env: Constant::ENV_PROD, debug: false);
    }

    /**
     * APP_SECRET, secret racine : avec le secret du pont, il suffit à démarrer en
     * production ; la clé CSRF en dérive, WAFFLE_CSRF_SECRET n'est plus exigé.
     */
    #[Test]
    public function create_boots_in_prod_with_app_secret_and_the_auth_secret_only(): void
    {
        putenv('APP_SECRET=' . self::ephemeralSecret());
        putenv('WAFFLE_AUTH_SECRET=' . self::ephemeralSecret());

        $kernel = AppKernelFactory::create(env: Constant::ENV_PROD, debug: false);

        self::assertInstanceOf(KernelInterface::class, $kernel);
    }

    /**
     * FIX-01 (audit Beta6) : une config YAML malformée ne doit plus faire
     * remonter d'exception non rattrapée à travers le boot du kernel —
     * AppKernelFactory::create() rattrape InvalidConfigurationException et
     * retente avec Failsafe::ENABLED. `tests/config/app.yaml` est le seul
     * fichier de config partagé par toute la suite : corrompu ici puis
     * restauré dans `finally`, jamais laissé dans un état invalide même en
     * cas d'échec de l'assertion.
     */
    #[Test]
    public function create_falls_back_to_failsafe_when_config_is_malformed(): void
    {
        putenv('WAFFLE_CSRF_SECRET=' . self::ephemeralSecret());
        putenv('WAFFLE_AUTH_SECRET=' . self::ephemeralSecret());

        $configPath = APP_ROOT . '/' . APP_CONFIG . '/app.yaml';
        $original = file_get_contents($configPath);
        self::assertIsString($original);

        file_put_contents($configPath, "waffle:\n  invalid: [ unclosed sequence\n");

        try {
            $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: true);

            self::assertInstanceOf(KernelInterface::class, $kernel);
        } finally {
            file_put_contents($configPath, $original);
        }
    }

    /**
     * CARRY-04 : le container du kernel fournit la factory PSR-17 des requêtes
     * serveur, avec laquelle le pont de test waffle-commons/testing construit ses
     * requêtes sans importer le composant http.
     */
    #[Test]
    public function the_kernel_container_provides_the_server_request_factory(): void
    {
        $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: true);

        self::assertInstanceOf(
            ServerRequestFactoryInterface::class,
            $kernel->container->get(ServerRequestFactoryInterface::class),
        );
    }

    /**
     * NET-02/03 : les deux clients sortants du conteneur sont enveloppés des reprises
     * (et du disjoncteur quand `waffle.http_client.breaker.enabled` est vrai) — le
     * fan-out concurrent comme le client à propagation d'assertion.
     */
    #[Test]
    public function the_kernel_container_provides_resilient_outbound_clients(): void
    {
        $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: true);

        self::assertInstanceOf(RetryingClient::class, $kernel->container->get(ClientInterface::class));
        self::assertInstanceOf(RetryingClient::class, $kernel->container->get(ConcurrentClientInterface::class));
    }
}
