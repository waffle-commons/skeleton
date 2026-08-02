<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\ConnectionPoolFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Contracts\Constant\Constant;

/**
 * BENCH-04 : la taille du pool PDO est pilotée par DB_POOL_SIZE, qui suit le
 * même flux que DB_HOST & co (`waffle.database.pool_size` interpolée depuis le
 * registre d'env injecté dans Config).
 *
 * Le registre est fourni ici directement au constructeur de Config — aucune
 * base de données requise : seul le parsing est verrouillé (défaut 8, entier
 * valide >= 1, repli sur le défaut si invalide). La construction du pool
 * elle-même est paresseuse (la fabrique n'ouvre une connexion que lorsque le
 * pool en a besoin), elle n'est donc pas exercée ici.
 */
final class ConnectionPoolFactoryTest extends AbstractTestCase
{
    /**
     * @param array<string, string> $env
     */
    private static function configWithEnv(array $env): Config
    {
        return new Config(configDir: APP_ROOT . '/' . APP_CONFIG, environment: Constant::ENV_DEV, env: $env);
    }

    #[Test]
    public function pool_size_defaults_to_eight_when_db_pool_size_is_absent(): void
    {
        self::assertSame(8, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv([])));
    }

    #[Test]
    public function pool_size_reads_a_valid_db_pool_size_from_the_environment(): void
    {
        self::assertSame(32, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv(['DB_POOL_SIZE' => '32'])));
        self::assertSame(1, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv(['DB_POOL_SIZE' => '1'])));
    }

    #[Test]
    public function pool_size_falls_back_to_eight_when_db_pool_size_is_invalid(): void
    {
        // Zéro et négatif violeraient l'invariant du pool (>= 1) ; une valeur
        // non numérique est un défaut de configuration — jamais bloquant pour
        // le boot, on retombe sur le plafond historique.
        self::assertSame(8, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv(['DB_POOL_SIZE' => '0'])));
        self::assertSame(8, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv(['DB_POOL_SIZE' => '-4'])));
        self::assertSame(8, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv(['DB_POOL_SIZE' => 'huit'])));
        self::assertSame(8, ConnectionPoolFactory::resolvePoolSize(self::configWithEnv(['DB_POOL_SIZE' => ''])));
    }
}
