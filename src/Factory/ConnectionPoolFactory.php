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
     * [CARRY-01] : fenêtre de fraîcheur du ping par défaut, en millisecondes. Une
     * connexion prouvée vivante (ouverte, ou ayant répondu au ping) il y a moins
     * de 500 ms repart du pool sans nouveau `SELECT 1` — le seuil par défaut de
     * HikariCP (`aliveBypassWindowMs`). Utilisée quand DB_PING_FRESHNESS_MS est
     * absente ou invalide ; 0 rétablit le ping à chaque emprunt.
     */
    private const int DEFAULT_PING_FRESHNESS_MS = 500;

    /**
     * Construit le pool de connexions PDO (RFC-022) à partir de `waffle.database.*`.
     *
     * La fabrique injectée n'ouvre une connexion que lorsque le pool en a besoin :
     * en mode worker FrankenPHP, les sockets restent tièdes entre les requêtes,
     * sont sondés (« ping-before-dispense », sauf dans la fenêtre de fraîcheur)
     * puis reconnectés de façon transparente.
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
        ]), maxConnections: self::resolvePoolSize($config), pingQuery: $ping, tracker: $tracker, pingFreshnessMs: self::resolvePingFreshness($config));
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
        return self::resolveInt($config, 'waffle.database.pool_size', 1, self::DEFAULT_POOL_SIZE);
    }

    /**
     * [CARRY-01] : fenêtre de fraîcheur du ping, en millisecondes
     * (`waffle.database.ping_freshness_ms`, interpolée depuis DB_PING_FRESHNESS_MS),
     * validée en entier >= 0 — 0 est légitime et rétablit le ping à chaque
     * emprunt. Absente, vide ou invalide ⇒ défaut 500.
     */
    public static function resolvePingFreshness(Config $config): int
    {
        return self::resolveInt($config, 'waffle.database.ping_freshness_ms', 0, self::DEFAULT_PING_FRESHNESS_MS);
    }

    /**
     * Lit un entier de configuration interpolé depuis l'environnement (donc une
     * chaîne). Absent, vide, non entier ou sous le minimum ⇒ le défaut : une
     * valeur fautive n'empêche jamais le boot.
     */
    private static function resolveInt(Config $config, string $key, int $minimum, int $default): int
    {
        $raw = $config->getString($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= $minimum ? $value : $default;
    }

    /**
     * Construit le DSN PDO propre à chaque moteur (RFC-022).
     *
     * PostgreSQL ne reconnaît pas l'attribut DSN `charset` (l'encodage client est
     * négocié avec le serveur — UTF8 par défaut) ; SQLite n'attend qu'un chemin de
     * fichier ; SQL Server et Oracle ont leur propre grammaire de DSN. MySQL et
     * MariaDB partagent la leur ; tout autre pilote reçoit la forme commune, sous
     * son propre nom.
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
            // MariaDB partage la grammaire DSN de MySQL : les deux sont nommés
            // explicitement plutôt que confiés au cas par défaut.
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $port,
                $database,
                $charset,
            ),
            // Tout autre pilote PDO reçoit la forme commune `pilote:host=…` sous SON
            // nom : une faute de frappe comme `postgres` échoue sur ce nom, au lieu
            // d'une tentative de connexion MySQL qui échouerait loin de l'erreur.
            default => sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $host, $port, $database),
        };
    }
}
