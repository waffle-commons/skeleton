<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\ConnectionPoolFactory;
use AppTests\AbstractTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Data\Connection\PDOConnectionPool;

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

    /**
     * Configuration de fixture dont chaque valeur `waffle.database.*` est
     * interpolée depuis le registre d'environnement : un seul fichier suffit
     * pour couvrir la grammaire de DSN de tous les moteurs.
     *
     * @param array<string, string> $env
     */
    private static function configWithFixture(array $env): Config
    {
        return new Config(configDir: __DIR__ . '/../Fixture/config', environment: Constant::ENV_DEV, env: $env);
    }

    /**
     * Chaque moteur a sa propre grammaire de DSN (RFC-022). Un moteur inconnu ne
     * doit PLUS retomber sur la forme MySQL — c'est le correctif beta6 : une
     * faute de frappe comme « postgres » produisait silencieusement une tentative
     * de connexion MySQL, qui échouait loin de la cause.
     *
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'postgresql' => ['pgsql'];
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
        yield 'sqlserver' => ['sqlsrv'];
        yield 'oracle' => ['oci'];
        yield 'moteur inconnu' => ['postgres'];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function the_pool_is_built_lazily_for_every_engine(string $driver): void
    {
        $pool = ConnectionPoolFactory::create(self::configWithFixture([
            'DB_DRIVER' => $driver,
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'waffle_test',
            'DB_USERNAME' => 'waffle',
            'DB_CHARSET' => 'utf8',
        ]));

        // Paresseux par construction : le DSN est assemblé, mais AUCUNE socket
        // n'est ouverte tant que le pool ne distribue pas de connexion.
        self::assertInstanceOf(PDOConnectionPool::class, $pool);
        self::assertSame(0, $pool->idleCount());
        self::assertSame(0, $pool->activeCount());
    }

    #[Test]
    public function the_sqlite_dsn_actually_opens_a_connection(): void
    {
        // SQLite en mémoire prouve la grammaire de bout en bout : si le DSN était
        // faux, `acquire()` lèverait au lieu de rendre un PDO utilisable.
        $pool = ConnectionPoolFactory::create(self::configWithFixture([
            'DB_DRIVER' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ]));

        $pdo = $pool->acquire()->pdo();

        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, $pool->activeCount());

        $pool->reset();
    }
}
