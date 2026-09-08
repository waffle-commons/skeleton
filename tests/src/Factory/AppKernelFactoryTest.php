<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\AppKernelFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;

/**
 * FIX-01 #9 (audit Beta6) : régression du garde-fou fail-closed
 * `APP_ENV=prod` + `APP_DEBUG=true`, ajouté dans AppKernelFactory::create().
 *
 * `temp_config/app.yaml` (cf. tests/bootstrap.php) laisse les secrets CSRF/Auth
 * vides — hors production, resolveCsrfSecret()/resolveAuthSecret() retombent
 * sur un secret éphémère. En production, ils redeviennent stricts : on fournit
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
        putenv('WAFFLE_CSRF_SECRET');
        putenv('WAFFLE_AUTH_SECRET');

        parent::tearDown();
    }

    /**
     * Génère un secret éphémère (>= 32 octets) pour isoler ce test des
     * vérifications resolveCsrfSecret()/resolveAuthSecret() — jamais un
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
     * Régression voisine (même famille fail-closed que #9) : `temp_config/app.yaml`
     * laisse `waffle.security.csrf.secret` vide et aucun WAFFLE_CSRF_SECRET n'est
     * exporté dans ce conteneur — resolveCsrfSecret() doit donc refuser de démarrer
     * en production plutôt que de retomber silencieusement sur un secret éphémère.
     */
    #[Test]
    public function create_throws_when_prod_csrf_secret_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CSRF/');

        AppKernelFactory::create(env: Constant::ENV_PROD, debug: false);
    }

    /**
     * Même discipline pour resolveAuthSecret() (RFC-021) : un CSRF secret valide
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
     * FIX-01 (audit Beta6) : une config YAML malformée ne doit plus faire
     * remonter d'exception non rattrapée à travers le boot du kernel —
     * AppKernelFactory::create() rattrape InvalidConfigurationException et
     * retente avec Failsafe::ENABLED. `temp_config/app.yaml` est le seul
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
}
