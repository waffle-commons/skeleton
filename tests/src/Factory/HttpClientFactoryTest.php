<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\HttpClientFactory;
use AppTests\AbstractTestCase;
use AppTests\Fixture\ArrayConfig;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\Exception\CircuitOpenExceptionInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\HttpClient\TimeoutPolicy;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Resilience\CircuitObservers;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;

/**
 * La résilience sortante du squelette (NET-02/03) : politiques lues dans la
 * configuration, clients résilients dans le conteneur, disjoncteur sur le Redis du
 * service compose `waffle-redis`.
 */
final class HttpClientFactoryTest extends AbstractTestCase
{
    private const string REDIS = 'redis://waffle-redis:6379';

    #[Test]
    public function the_policies_come_from_the_configuration(): void
    {
        $config = new ArrayConfig([
            'waffle.http_client.resilience.connect_timeout_ms' => 500,
            'waffle.http_client.resilience.total_timeout_ms' => 4000,
            'waffle.http_client.resilience.max_attempts' => 5,
            'waffle.http_client.resilience.base_delay_ms' => 50,
            'waffle.http_client.resilience.max_delay_ms' => 800,
            'waffle.http_client.resilience.budget_ms' => 6000,
            'waffle.http_client.resilience.retry_non_idempotent' => true,
            'waffle.http_client.breaker.failure_rate_percent' => 25,
            'waffle.http_client.breaker.minimum_calls' => 4,
            'waffle.http_client.breaker.window_seconds' => 30,
            'waffle.http_client.breaker.cool_down_seconds' => 15,
            'waffle.http_client.breaker.half_open_trials' => 2,
        ]);

        self::assertEquals(new TimeoutPolicy(500, 4000), HttpClientFactory::timeouts($config));
        self::assertEquals(new RetryPolicy(5, 50, 800, 6000, true), HttpClientFactory::retryPolicy($config));
        self::assertEquals(new CircuitBreakerPolicy(25, 4, 30, 15, 2), HttpClientFactory::breakerPolicy($config));
    }

    #[Test]
    public function a_missing_key_takes_the_contract_default(): void
    {
        $config = new ArrayConfig([]);

        self::assertEquals(new TimeoutPolicy(), HttpClientFactory::timeouts($config));
        self::assertEquals(new RetryPolicy(), HttpClientFactory::retryPolicy($config));
        self::assertEquals(new CircuitBreakerPolicy(), HttpClientFactory::breakerPolicy($config));
    }

    #[Test]
    public function the_container_receives_resilient_clients(): void
    {
        $container = new Container();
        $transport = new Client(new ResponseFactory(), new StreamFactory());

        HttpClientFactory::register(
            $container,
            new ArrayConfig([
                'waffle.http_client.breaker.enabled' => true,
                'waffle.http_client.breaker.redis_dsn' => self::REDIS,
            ]),
            $transport,
            $transport,
            new CircuitObservers(),
        );

        self::assertInstanceOf(RetryingClient::class, $container->get(ConcurrentClientInterface::class));
        self::assertInstanceOf(RetryingClient::class, $container->get(ClientInterface::class));
    }

    #[Test]
    public function an_open_circuit_answers_without_calling_the_transport(): void
    {
        $config = new ArrayConfig([
            'waffle.http_client.breaker.redis_dsn' => self::REDIS,
            'waffle.http_client.resilience.max_attempts' => 1,
            'waffle.http_client.breaker.minimum_calls' => 2,
        ]);
        $transport = new class implements ClientInterface {
            public int $calls = 0;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return new ResponseFactory()->createResponse(503);
            }
        };
        $client = HttpClientFactory::resilient(
            $transport,
            $config,
            HttpClientFactory::circuitStorage($config),
            new CircuitObservers(),
        );
        // Une origine neuve à chaque exécution : son circuit n'a pas d'histoire.
        $request = new RequestFactory()->createRequest(
            'GET',
            'https://amont-' . bin2hex(random_bytes(6)) . '.example/x',
        );

        self::assertSame(503, $client->sendRequest($request)->getStatusCode());
        self::assertSame(503, $client->sendRequest($request)->getStatusCode());

        try {
            $client->sendRequest($request);
            self::fail('Deux échecs sur deux appels doivent ouvrir le circuit.');
        } catch (CircuitOpenExceptionInterface $ouvert) {
            self::assertSame(503, $ouvert->getCode());
            self::assertSame(2, $transport->calls);
        }
    }

    #[Test]
    public function without_a_breaker_the_retries_apply_alone(): void
    {
        $transport = new class implements ClientInterface {
            public int $calls = 0;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return new ResponseFactory()->createResponse($this->calls === 1 ? 503 : 200);
            }
        };
        $config = new ArrayConfig(['waffle.http_client.resilience.base_delay_ms' => 1]);

        $client = HttpClientFactory::resilient($transport, $config, null, new CircuitObservers());

        self::assertSame(
            200,
            $client
                ->sendRequest(new RequestFactory()->createRequest('GET', 'https://amont.example/x'))
                ->getStatusCode(),
        );
        self::assertSame(2, $transport->calls);
    }
}
