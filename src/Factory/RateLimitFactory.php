<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;
use Waffle\Commons\Http\ClientIp\TrustedProxyClientIpResolver;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;
use Waffle\Commons\Security\RateLimit\RateLimiter;
use Waffle\Commons\Security\RateLimit\StorageFailureMode;

/**
 * Limitation de débit de l'application (NET-01, RFC-017 §3.1), hors de
 * l'AppKernelFactory pour tenir sa complexité sous le seuil de 50.
 *
 * Un seau à jetons partagé dans Redis (jamais en mémoire du worker), clé = sujet
 * authentifié, sinon IP cliente résolue derrière les proxys de confiance. Pas de
 * middleware (null) quand `waffle.security.rate_limit.enabled` n'est pas vrai. Une panne de Redis applique
 * `on_storage_failure` avec un avertissement et la métrique `waffle_ratelimit_storage_errors_total`.
 */
final class RateLimitFactory
{
    private const string KEY = 'waffle.security.rate_limit.';

    public static function middleware(
        ConfigInterface $config,
        SecurityContextInterface $securityContext,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
        ?MetricsRegistryInterface $metrics = null,
    ): ?RateLimitMiddleware {
        if ($config->getBool(self::KEY . 'enabled') !== true) {
            return null;
        }

        $proxies = [];
        foreach ($config->getArray(key: self::KEY . 'trusted_proxies') ?? [] as $proxy) {
            if (!is_string($proxy)) {
                continue;
            }

            $proxies[] = $proxy;
        }

        $limiter = new RateLimiter(
            new RedisLimiterStateStorage(RedisPoolFactory::create(
                $config->getString(key: self::KEY . 'redis_dsn') ?? 'redis://127.0.0.1:6379',
            )),
            new TokenBucket(
                $config->getInt(self::KEY . 'capacity') ?? 120,
                $config->getInt(self::KEY . 'period_seconds') ?? 60,
            ),
            StorageFailureMode::tryFrom($config->getString(self::KEY . 'on_storage_failure') ?? '')
            ?? StorageFailureMode::Open,
            $logger,
            $metrics,
        );

        return new RateLimitMiddleware(
            $limiter,
            new TrustedProxyClientIpResolver($proxies),
            $securityContext,
            $responseFactory,
        );
    }
}
