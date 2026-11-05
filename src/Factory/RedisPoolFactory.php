<?php

declare(strict_types=1);

namespace App\Factory;

use InvalidArgumentException;
use Redis;
use Waffle\Commons\Data\Connection\RedisConnectionPool;

/**
 * Pool de connexions Redis (phpredis) de l'état partagé entre tous les workers :
 * seaux du limiteur de débit (NET-01) et circuits du disjoncteur (NET-03). Le cache
 * PSR-16, lui, passe par Predis (`waffle.cache.*`), hors de ce pool (RFC-024 §5.1).
 */
final class RedisPoolFactory
{
    /**
     * RFC-023 §3.2 : délai de lecture par défaut, en secondes. Un Redis qui accepte
     * la connexion puis ne répond plus fait échouer la commande en une seconde au
     * lieu de bloquer le worker pendant le `default_socket_timeout` de PHP (60 s).
     */
    public const float READ_TIMEOUT = 1.0;

    /**
     * Construit le pool à partir d'un DSN `redis://[:mot-de-passe@]hôte[:port]`.
     *
     * Comme le pool PDO, il n'ouvre une connexion que lorsqu'on lui en emprunte
     * une, et la sonde `PING` écarte une connexion morte avant de la prêter. La
     * connexion s'ouvre en une seconde au plus, et chaque lecture attend au plus
     * `$readTimeout` secondes ; `null` garde le délai de PHP, pour un client qui
     * fait des lectures bloquantes plus longues (un `queue:work`, par exemple).
     *
     * @throws InvalidArgumentException Si le DSN est illisible (son contenu n'est pas
     *                                  repris dans le message : il peut porter un mot de passe).
     */
    public static function create(string $dsn, ?float $readTimeout = self::READ_TIMEOUT): RedisConnectionPool
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new InvalidArgumentException('Le DSN Redis est illisible.');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;
        $password = array_key_exists('pass', $parts) ? rawurldecode($parts['pass']) : null;

        return new RedisConnectionPool(
            factory: static function () use ($host, $port, $password, $readTimeout): Redis {
                $redis = new Redis();
                $redis->connect($host, $port, 1.0);
                if ($readTimeout !== null) {
                    $redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
                }

                if ($password !== null) {
                    $redis->auth($password);
                }

                return $redis;
            },
            healthCheck: static fn(object $client): bool => $client instanceof Redis && $client->ping() !== false,
        );
    }
}
