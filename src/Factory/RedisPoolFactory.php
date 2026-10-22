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
     * Construit le pool à partir d'un DSN `redis://[:mot-de-passe@]hôte[:port]`.
     *
     * Comme le pool PDO, il n'ouvre une connexion que lorsqu'on lui en emprunte
     * une, et la sonde `PING` écarte une connexion morte avant de la prêter.
     *
     * @throws InvalidArgumentException Si le DSN est illisible (son contenu n'est pas
     *                                  repris dans le message : il peut porter un mot de passe).
     */
    public static function create(string $dsn): RedisConnectionPool
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new InvalidArgumentException('Le DSN Redis est illisible.');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;
        $password = array_key_exists('pass', $parts) ? rawurldecode($parts['pass']) : null;

        return new RedisConnectionPool(
            factory: static function () use ($host, $port, $password): Redis {
                $redis = new Redis();
                $redis->connect($host, $port, 1.0);
                if ($password !== null) {
                    $redis->auth($password);
                }

                return $redis;
            },
            healthCheck: static fn(object $client): bool => $client instanceof Redis && $client->ping() !== false,
        );
    }
}
