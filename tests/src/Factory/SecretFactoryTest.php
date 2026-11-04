<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\SecretFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Security\Csrf\CsrfTokenManager;

/**
 * APP_SECRET, secret racine : la clé CSRF en dérive (HKDF-SHA256) quand
 * WAFFLE_CSRF_SECRET est vide, la même dans chaque worker ; le secret du pont
 * d'authentification reste explicite. La production refuse de démarrer sans
 * secret valable.
 *
 * Les secrets sont fabriqués (str_repeat) plutôt qu'écrits en clair : un littéral
 * de secret est refusé par le linter, y compris dans les tests.
 */
final class SecretFactoryTest extends AbstractTestCase
{
    /** HKDF-SHA256(APP_SECRET = 64 × « k », sel vide, info « waffle-commons/csrf/v1 », 32 octets). */
    private const string CSRF_KEY_OF_64_K = 'f384243a6e3ce37742a3d58e6a33d7b024a39d6477e34b986c1c5fbc7d0ffa67';

    #[\Override]
    protected function tearDown(): void
    {
        putenv('APP_SECRET');
        putenv('WAFFLE_CSRF_SECRET');
        putenv('WAFFLE_AUTH_SECRET');

        parent::tearDown();
    }

    /**
     * @param array<string, string> $values
     */
    private function config(array $values): ConfigInterface
    {
        return new class($values) implements ConfigInterface {
            /**
             * @param array<string, string> $values
             */
            public function __construct(
                private readonly array $values,
            ) {}

            #[\Override]
            public function getInt(string $key, ?int $default = null): ?int
            {
                return $default;
            }

            #[\Override]
            public function getString(string $key, ?string $default = null): ?string
            {
                return $this->values[$key] ?? $default;
            }

            #[\Override]
            public function getArray(string $key, ?array $default = null): ?array
            {
                return $default;
            }

            #[\Override]
            public function getBool(string $key, ?bool $default = null): ?bool
            {
                return $default;
            }
        };
    }

    #[Test]
    public function the_csrf_key_is_hkdf_sha256_of_app_secret(): void
    {
        $config = $this->config(['waffle.secret' => str_repeat('k', 64)]);

        // Vecteur figé : changer l'algorithme, la longueur ou le contexte invaliderait
        // tous les jetons en circulation, et doit donc être une décision explicite.
        self::assertSame(self::CSRF_KEY_OF_64_K, bin2hex(SecretFactory::csrf($config, Constant::ENV_PROD)));
    }

    #[Test]
    public function every_worker_derives_the_same_csrf_key(): void
    {
        // Chaque appel tient lieu d'un worker qui démarre : la clé ne doit plus être
        // tirée au hasard, en développement comme en production.
        $config = $this->config(['waffle.secret' => str_repeat('r', 40)]);

        self::assertSame(
            SecretFactory::csrf($config, Constant::ENV_DEV),
            SecretFactory::csrf($config, Constant::ENV_DEV),
        );
        self::assertSame(
            SecretFactory::csrf($config, Constant::ENV_PROD),
            SecretFactory::csrf($config, Constant::ENV_DEV),
        );
    }

    /**
     * Deux gestionnaires tiennent lieu de deux workers, construits comme le fait
     * AppKernelFactory : avec le même APP_SECRET, un jeton émis par l'un est accepté
     * par l'autre ; une application dont l'APP_SECRET diffère le refuse.
     */
    #[Test]
    public function a_csrf_token_issued_by_one_worker_validates_on_another(): void
    {
        $config = $this->config(['waffle.secret' => str_repeat('r', 40)]);
        $issuer = new CsrfTokenManager(secret: SecretFactory::csrf($config, Constant::ENV_DEV));
        $verifier = new CsrfTokenManager(secret: SecretFactory::csrf($config, Constant::ENV_DEV));
        $otherApp = new CsrfTokenManager(secret: SecretFactory::csrf($this->config(['waffle.secret' => str_repeat(
            'o',
            40,
        )]), Constant::ENV_DEV));

        $sessionId = bin2hex(random_bytes(16));
        $token = $issuer->issue('form:demo', $sessionId)->getValue();

        self::assertTrue($verifier->validate('form:demo', $sessionId, $token));
        self::assertFalse($otherApp->validate('form:demo', $sessionId, $token));
    }

    #[Test]
    public function the_csrf_key_is_never_app_secret_itself(): void
    {
        // Une « dérivation » par troncature recopierait 32 octets du secret racine.
        $root = str_repeat('r', 32) . str_repeat('s', 32);
        $key = SecretFactory::csrf($this->config(['waffle.secret' => $root]), Constant::ENV_PROD);

        self::assertSame(32, strlen($key));
        self::assertStringNotContainsString($key, $root);
    }

    #[Test]
    public function an_explicit_csrf_secret_wins_over_app_secret(): void
    {
        $explicit = str_repeat('c', 32);
        $config = $this->config([
            'waffle.secret' => str_repeat('r', 32),
            'waffle.security.csrf.secret' => $explicit,
        ]);

        self::assertSame($explicit, SecretFactory::csrf($config, Constant::ENV_PROD));
    }

    #[Test]
    public function the_process_environment_is_read_when_the_config_keys_are_empty(): void
    {
        $config = $this->config(['waffle.secret' => '', 'waffle.security.csrf.secret' => '']);

        putenv('APP_SECRET=' . str_repeat('k', 64));
        self::assertSame(self::CSRF_KEY_OF_64_K, bin2hex(SecretFactory::csrf($config, Constant::ENV_PROD)));

        $explicit = str_repeat('e', 32);
        putenv('WAFFLE_CSRF_SECRET=' . $explicit);
        self::assertSame($explicit, SecretFactory::csrf($config, Constant::ENV_PROD));
    }

    #[Test]
    public function production_refuses_a_short_explicit_csrf_secret_even_with_app_secret(): void
    {
        $config = $this->config([
            'waffle.secret' => str_repeat('r', 32),
            'waffle.security.csrf.secret' => str_repeat('c', 20),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Secret CSRF plus court que 32 octets/');

        SecretFactory::csrf($config, Constant::ENV_PROD);
    }

    #[Test]
    public function outside_production_a_short_explicit_csrf_secret_gives_way_to_app_secret(): void
    {
        // Un .env d'avant la dérivation garde WAFFLE_CSRF_SECRET=ChangeMeInProduction
        // (20 octets) : en développement, la clé dérive alors d'APP_SECRET.
        $root = str_repeat('r', 32);
        $withShort = $this->config(['waffle.secret' => $root, 'waffle.security.csrf.secret' => str_repeat('c', 20)]);
        $withoutCsrf = $this->config(['waffle.secret' => $root]);

        self::assertSame(
            SecretFactory::csrf($withoutCsrf, Constant::ENV_DEV),
            SecretFactory::csrf($withShort, Constant::ENV_DEV),
        );
    }

    #[Test]
    public function production_refuses_an_app_secret_shorter_than_32_bytes(): void
    {
        $config = $this->config(['waffle.secret' => str_repeat('r', 31)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Secret CSRF manquant en production : renseignez APP_SECRET/');

        SecretFactory::csrf($config, Constant::ENV_PROD);
    }

    #[Test]
    public function production_refuses_to_boot_without_any_csrf_secret(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET.*WAFFLE_CSRF_SECRET/');

        SecretFactory::csrf($this->config([]), Constant::ENV_PROD);
    }

    #[Test]
    public function outside_production_without_any_secret_the_csrf_key_is_random_per_process(): void
    {
        $first = SecretFactory::csrf($this->config([]), Constant::ENV_DEV);

        self::assertSame(32, strlen($first));
        self::assertNotSame($first, SecretFactory::csrf($this->config([]), Constant::ENV_DEV));
    }

    #[Test]
    public function the_auth_secret_comes_from_its_config_key_then_the_environment(): void
    {
        $fromConfig = str_repeat('a', 32);
        self::assertSame($fromConfig, SecretFactory::auth($this->config([
            'waffle.auth.secret' => $fromConfig,
        ]), Constant::ENV_PROD));

        $fromEnv = str_repeat('b', 32);
        putenv('WAFFLE_AUTH_SECRET=' . $fromEnv);
        self::assertSame($fromEnv, SecretFactory::auth($this->config([
            'waffle.auth.secret' => '',
        ]), Constant::ENV_PROD));
    }

    #[Test]
    public function the_auth_secret_never_derives_from_app_secret(): void
    {
        // Les autres services du pont partagent ce secret : APP_SECRET, propre à
        // l'application, ne peut pas le remplacer.
        $config = $this->config(['waffle.secret' => str_repeat('r', 64)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pont d\'authentification/');

        SecretFactory::auth($config, Constant::ENV_PROD);
    }

    #[Test]
    public function outside_production_without_an_auth_secret_it_is_random_per_process(): void
    {
        $first = SecretFactory::auth($this->config([]), Constant::ENV_DEV);

        self::assertSame(32, strlen($first));
        self::assertNotSame($first, SecretFactory::auth($this->config([]), Constant::ENV_DEV));
    }
}
