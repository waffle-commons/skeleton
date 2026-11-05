<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Client\ClientInterface;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\HttpClient\TimeoutPolicy;
use Waffle\Commons\Data\CircuitBreaker\RedisCircuitStateStorage;
use Waffle\Commons\HttpClient\Resilience\CircuitBreakerClient;
use Waffle\Commons\HttpClient\Resilience\CircuitObservers;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;

/**
 * Résilience sortante de l'application (NET-02/03, RFC-024), hors de
 * l'AppKernelFactory pour tenir sa complexité sous le seuil de 50.
 *
 * Chaque client sortant est composé RetryingClient(CircuitBreakerClient(client)) :
 * chaque tentative repasse par le disjoncteur, dont le refus n'est jamais retenté,
 * et rejoue la garde SSRF du client. Les politiques viennent de
 * `waffle.http_client.resilience.*` et `waffle.http_client.breaker.*` (app.yaml) ;
 * une clé absente prend la valeur par défaut du contrat. L'état des disjoncteurs
 * vit dans Redis, jamais en mémoire du worker : tous les workers voient le même.
 */
final class HttpClientFactory
{
    private const string RESILIENCE = 'waffle.http_client.resilience.';

    private const string BREAKER = 'waffle.http_client.breaker.';

    /**
     * Enregistre les clients sortants résilients : ConcurrentClientInterface pour le
     * fan-out (sans identifiants) et ClientInterface (avec la propagation d'assertion,
     * réappliquée à chaque tentative). Le disjoncteur n'est posé que si
     * `waffle.http_client.breaker.enabled` est vrai.
     */
    public static function register(
        ContainerInterface $container,
        ConfigInterface $config,
        ConcurrentClientInterface&ClientInterface $transport,
        ClientInterface $authenticated,
        CircuitObservers $observers,
    ): void {
        $storage = $config->getBool(self::BREAKER . 'enabled') === true ? self::circuitStorage($config) : null;

        $container->set(ConcurrentClientInterface::class, self::resilient($transport, $config, $storage, $observers));
        $container->set(ClientInterface::class, self::resilient($authenticated, $config, $storage, $observers));
    }

    /**
     * Enveloppe `$client` dans les reprises et, avec un stockage, dans le disjoncteur.
     */
    public static function resilient(
        ClientInterface $client,
        ConfigInterface $config,
        ?CircuitStateStorageInterface $storage,
        CircuitObservers $observers,
    ): RetryingClient {
        if ($storage !== null) {
            $client = new CircuitBreakerClient($client, $storage, self::breakerPolicy($config), $observers);
        }

        return new RetryingClient($client, self::retryPolicy($config));
    }

    /** Les délais d'un transfert : jamais infinis, bornés par le contrat. */
    public static function timeouts(ConfigInterface $config): TimeoutPolicy
    {
        return new TimeoutPolicy(
            $config->getInt(self::RESILIENCE . 'connect_timeout_ms') ?? TimeoutPolicy::DEFAULT_CONNECT_MS,
            $config->getInt(self::RESILIENCE . 'total_timeout_ms') ?? TimeoutPolicy::DEFAULT_TOTAL_MS,
        );
    }

    public static function retryPolicy(ConfigInterface $config): RetryPolicy
    {
        $defaults = new RetryPolicy();

        return new RetryPolicy(
            $config->getInt(self::RESILIENCE . 'max_attempts') ?? $defaults->maxAttempts,
            $config->getInt(self::RESILIENCE . 'base_delay_ms') ?? $defaults->baseDelayMs,
            $config->getInt(self::RESILIENCE . 'max_delay_ms') ?? $defaults->maxDelayMs,
            $config->getInt(self::RESILIENCE . 'budget_ms') ?? $defaults->budgetMs,
            $config->getBool(self::RESILIENCE . 'retry_non_idempotent') ?? $defaults->retryNonIdempotent,
        );
    }

    public static function breakerPolicy(ConfigInterface $config): CircuitBreakerPolicy
    {
        $defaults = new CircuitBreakerPolicy();

        return new CircuitBreakerPolicy(
            $config->getInt(self::BREAKER . 'failure_rate_percent') ?? $defaults->failureRatePercent,
            $config->getInt(self::BREAKER . 'minimum_calls') ?? $defaults->minimumCalls,
            $config->getInt(self::BREAKER . 'window_seconds') ?? $defaults->windowSeconds,
            $config->getInt(self::BREAKER . 'cool_down_seconds') ?? $defaults->coolDownSeconds,
            $config->getInt(self::BREAKER . 'half_open_trials') ?? $defaults->halfOpenTrials,
        );
    }

    /** L'état partagé des disjoncteurs, dans le Redis de `waffle.http_client.breaker.redis_dsn`. */
    public static function circuitStorage(ConfigInterface $config): RedisCircuitStateStorage
    {
        return new RedisCircuitStateStorage(RedisPoolFactory::create(
            $config->getString(key: self::BREAKER . 'redis_dsn') ?? 'redis://127.0.0.1:6379',
        ));
    }
}
