<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\RateLimitFactory;
use AppTests\AbstractTestCase;
use AppTests\Fixture\ArrayConfig;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Waffle\Commons\Auth\SecurityContext;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;
use Waffle\Commons\Security\RateLimit\RateLimiter;

/**
 * La limitation de débit du squelette (NET-01) : aucun middleware quand elle est
 * coupée ; construite, elle compte dans un seau partagé sur le Redis du service compose
 * `waffle-redis`, et suit son mode d'échec — métrique comprise — quand ce Redis ne répond pas.
 *
 * Les seaux survivent à l'exécution (ils vivent dans Redis) : chaque test compte pour
 * une IP de documentation tirée au hasard, jamais deux fois la même.
 */
final class RateLimitFactoryTest extends AbstractTestCase
{
    private const string KEY = 'waffle.security.rate_limit.';

    #[Test]
    public function nothing_is_built_when_the_limiter_is_disabled(): void
    {
        $config = new ArrayConfig([self::KEY . 'enabled' => false]);

        self::assertNull(RateLimitFactory::middleware(
            $config,
            new SecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
        ));
    }

    #[Test]
    public function a_storage_outage_is_counted_in_the_metrics_registry(): void
    {
        $metrics = $this->createMock(MetricsRegistryInterface::class);
        $metrics->expects($this->once())->method('increment')->with(RateLimiter::STORAGE_ERRORS_METRIC, 1.0, [
            'mode' => 'open',
        ]);
        $limiter = RateLimitFactory::middleware(
            new ArrayConfig([self::KEY . 'enabled' => true, self::KEY . 'redis_dsn' => 'redis://127.0.0.1:1']),
            new SecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
            $metrics,
        );
        self::assertInstanceOf(RateLimitMiddleware::class, $limiter);

        self::assertSame(
            200,
            $limiter->process(self::request(self::documentationIp()), self::handler())->getStatusCode(),
        );
    }

    #[Test]
    public function the_shared_bucket_empties_then_answers_429_with_retry_after(): void
    {
        $limiter = self::limiter(self::config([self::KEY . 'capacity' => 2]));
        $client = self::documentationIp();

        $premier = $limiter->process(self::request($client), self::handler());
        $second = $limiter->process(self::request($client), self::handler());
        $refus = $limiter->process(self::request($client), self::handler());

        self::assertSame(200, $premier->getStatusCode());
        self::assertSame('2', $premier->getHeaderLine('RateLimit-Limit'));
        self::assertSame('1', $premier->getHeaderLine('RateLimit-Remaining'));
        self::assertSame('0', $second->getHeaderLine('RateLimit-Remaining'));
        self::assertSame(429, $refus->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $refus->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function behind_a_trusted_proxy_each_forwarded_client_has_its_own_bucket(): void
    {
        $limiter = self::limiter(self::config([
            self::KEY . 'capacity' => 1,
            // Une entrée qui n'est pas une chaîne est ignorée, pas fatale.
            self::KEY . 'trusted_proxies' => ['10.0.0.0/8', 42],
        ]));
        $alice = self::documentationIp();
        $bob = self::documentationIp();

        self::assertSame(200, $limiter->process(self::request('10.1.2.3', $alice), self::handler())->getStatusCode());
        self::assertSame(200, $limiter->process(self::request('10.1.2.3', $bob), self::handler())->getStatusCode());
        self::assertSame(429, $limiter->process(self::request('10.1.2.3', $alice), self::handler())->getStatusCode());
    }

    #[Test]
    public function a_storage_outage_lets_the_request_through_in_open_mode(): void
    {
        // Mode inconnu ⇒ « open », le défaut documenté.
        $limiter = self::limiter(new ArrayConfig([
            self::KEY . 'enabled' => true,
            self::KEY . 'redis_dsn' => 'redis://127.0.0.1:1',
            self::KEY . 'on_storage_failure' => 'parfois',
        ]));

        self::assertSame(
            200,
            $limiter->process(self::request(self::documentationIp()), self::handler())->getStatusCode(),
        );
    }

    #[Test]
    public function a_storage_outage_answers_503_in_closed_mode(): void
    {
        $limiter = self::limiter(new ArrayConfig([
            self::KEY . 'enabled' => true,
            self::KEY . 'redis_dsn' => 'redis://127.0.0.1:1',
            self::KEY . 'on_storage_failure' => 'closed',
        ]));

        self::assertSame(
            503,
            $limiter->process(self::request(self::documentationIp()), self::handler())->getStatusCode(),
        );
    }

    /**
     * Le limiteur monté, sur le Redis de développement par défaut.
     *
     * @param array<string, int|string|bool|list<int|string>> $values
     */
    private static function config(array $values): ArrayConfig
    {
        return new ArrayConfig([
            self::KEY . 'enabled' => true,
            self::KEY . 'redis_dsn' => 'redis://waffle-redis:6379',
            ...$values,
        ]);
    }

    private static function limiter(ArrayConfig $config): RateLimitMiddleware
    {
        $limiter = RateLimitFactory::middleware(
            $config,
            new SecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
        );
        self::assertInstanceOf(RateLimitMiddleware::class, $limiter);

        return $limiter;
    }

    /** Une IPv6 de documentation (RFC 3849) neuve : son seau n'a pas d'histoire. */
    private static function documentationIp(): string
    {
        return sprintf('2001:db8::%x:%x', random_int(0, 0xffff), random_int(0, 0xffff));
    }

    private static function request(string $remoteAddress, ?string $forwardedFor = null): ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://localhost/', [
            'REMOTE_ADDR' => $remoteAddress,
        ]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new ResponseFactory()->createResponse(200);
            }
        };
    }
}
