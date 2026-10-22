<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;
use Waffle\Commons\Http\ClientIp\TrustedProxyClientIpResolver;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;
use Waffle\Commons\Security\RateLimit\RateLimiter;
use Waffle\Commons\Security\RateLimit\StorageFailureMode;

/**
 * Limitation de débit de l'application (NET-01, RFC-017 §3.1), hors de
 * l'AppKernelFactory pour tenir sa complexité sous le seuil de 50.
 *
 * Un seau à jetons partagé dans Redis (jamais en mémoire du worker), clé = sujet
 * authentifié, sinon IP cliente résolue derrière les proxys de confiance. Rien
 * n'est ajouté quand `waffle.security.rate_limit.enabled` n'est pas vrai.
 */
final class RateLimitFactory
{
    private const string KEY = 'waffle.security.rate_limit.';

    public static function register(
        MiddlewareStack $stack,
        ConfigInterface $config,
        SecurityContextInterface $securityContext,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
    ): void {
        $middleware = self::middleware($config, $securityContext, $responseFactory, $logger);
        if ($middleware !== null) {
            $stack->add(middleware: $middleware);
        }
    }

    public static function middleware(
        ConfigInterface $config,
        SecurityContextInterface $securityContext,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
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
        );

        return new RateLimitMiddleware(
            $limiter,
            new TrustedProxyClientIpResolver($proxies),
            $securityContext,
            $responseFactory,
        );
    }
}
