<?php

declare(strict_types=1);

namespace App\Factory;

use PDO;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Data\Connection\PDOConnectionPool;

/**
 * Fabrique dédiée du pool de connexions PDO (RFC-022), extraite de
 * AppKernelFactory : la grammaire des DSN par moteur et la validation de la
 * taille du pool sont des préoccupations de persistance, pas d'assemblage du
 * kernel (et la classe d'assemblage reste sous le plafond de complexité CPLX-04).
 */
final class ConnectionPoolFactory
{
    /**
     * BENCH-04 : plafond par défaut du pool PDO, aligné sur le défaut historique
     * de PDOConnectionPool (utilisé quand DB_POOL_SIZE est absente ou invalide).
     */
    private const int DEFAULT_POOL_SIZE = 8;

    /**
     * Construit le pool de connexions PDO (RFC-022) à partir de `waffle.database.*`.
     *
     * La fabrique injectée n'ouvre une connexion que lorsque le pool en a besoin :
     * en mode worker FrankenPHP, les sockets restent tièdes entre les requêtes,
     * sont sondés (« ping-before-dispense ») puis reconnectés de façon transparente.
     */
    public static function create(Config $config, ?ConnectionTrackerInterface $tracker = null): PDOConnectionPool
    {
        $driver = $config->getString('waffle.database.driver') ?? 'pgsql';
        $host = $config->getString('waffle.database.host') ?? '127.0.0.1';
        $port = $config->getString('waffle.database.port') ?? '5432';
        $database = $config->getString('waffle.database.database') ?? '';
        $username = $config->getString('waffle.database.username') ?? 'waffle';
        $password = $config->getString('waffle.database.password') ?? '';
        $charset = $config->getString('waffle.database.charset') ?? 'utf8';

        $dsn = self::buildDsn($driver, $host, $port, $database, $charset);

        // Oracle exige `FROM DUAL` pour un SELECT sans table : la sonde de vie
        // (« ping-before-dispense ») du pool doit donc être adaptée au moteur.
        $ping = $driver === 'oci' ? 'SELECT 1 FROM DUAL' : 'SELECT 1';

        // Fabrique sans état, rejouée à chaque création de connexion par le pool.
        return new PDOConnectionPool(factory: static fn(): PDO => new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]), maxConnections: self::resolvePoolSize($config), pingQuery: $ping, tracker: $tracker);
    }

    /**
     * BENCH-04 : taille du pool pilotable pour le test de saturation. La valeur
     * suit le même flux que DB_HOST & co (`waffle.database.pool_size`, interpolée
     * depuis DB_POOL_SIZE dans le registre .env + process) ; l'interpolation
     * d'environnement produit une chaîne, validée ici en entier >= 1. Absente,
     * vide ou invalide ⇒ défaut 8 (le plafond historique de PDOConnectionPool).
     */
    public static function resolvePoolSize(Config $config): int
    {
        $raw = $config->getString('waffle.database.pool_size');
        if ($raw === null || $raw === '') {
            return self::DEFAULT_POOL_SIZE;
        }

        $size = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($size) && $size >= 1 ? $size : self::DEFAULT_POOL_SIZE;
    }

    /**
     * Construit le DSN PDO propre à chaque moteur (RFC-022).
     *
     * PostgreSQL ne reconnaît pas l'attribut DSN `charset` (l'encodage client est
     * négocié avec le serveur — UTF8 par défaut) ; SQLite n'attend qu'un chemin de
     * fichier ; SQL Server et Oracle ont leur propre grammaire de DSN. Le format
     * MySQL/MariaDB reste le cas par défaut.
     */
    private static function buildDsn(
        string $driver,
        string $host,
        string $port,
        string $database,
        string $charset,
    ): string {
        return match ($driver) {
            'pgsql' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            'sqlite' => sprintf('sqlite:%s', $database),
            'sqlsrv' => sprintf('sqlsrv:Server=%s,%s;Database=%s', $host, $port, $database),
            'oci' => sprintf('oci:dbname=//%s:%s/%s;charset=%s', $host, $port, $database, $charset),
            // MariaDB shares MySQL's DSN grammar, so both are named explicitly
            // rather than relying on the fallback.
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $port,
                $database,
                $charset,
            ),
            // Any other PDO driver gets the common `driver:host=…` shape with
            // ITS OWN name. The previous fallback emitted a `mysql:` DSN for
            // every unrecognised value, so a typo like `postgres` silently
            // produced a MySQL connection attempt and failed somewhere far from
            // the mistake.
            default => sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $host, $port, $database),
        };
    }
}
