<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\RedisPoolFactory;
use AppTests\AbstractTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Redis;
use RedisException;

/**
 * Le pool Redis de l'état partagé (NET-01/03) : construit sans connexion, il prête
 * ensuite un client phpredis vivant sur le Redis du DSN — le service compose
 * `waffle-redis` (docker-compose.yml du squelette, ou celui du monorepo).
 */
final class RedisPoolFactoryTest extends AbstractTestCase
{
    private const string REDIS = 'redis://waffle-redis:6379';

    #[Test]
    public function an_unreadable_dsn_is_refused_without_repeating_it(): void
    {
        try {
            // Port hors plage : parse_url() refuse le DSN.
            RedisPoolFactory::create('redis://:dsn-secret@waffle-redis:99999');
            self::fail('Un DSN illisible doit être refusé.');
        } catch (InvalidArgumentException $refus) {
            self::assertStringNotContainsString('dsn-secret', $refus->getMessage());
        }
    }

    #[Test]
    public function the_pool_opens_no_connection_before_its_first_lease(): void
    {
        $pool = RedisPoolFactory::create(self::REDIS);

        self::assertSame(0, $pool->idleCount());
        self::assertSame(0, $pool->activeCount());
    }

    #[Test]
    public function a_lease_is_a_live_client_and_a_reused_one_is_probed_first(): void
    {
        $pool = RedisPoolFactory::create(self::REDIS);

        $first = $pool->acquire();
        $client = $first->client();
        self::assertInstanceOf(Redis::class, $client);
        self::assertTrue($client->ping());
        $pool->release($first);

        // La connexion inactive repasse par la sonde PING avant d'être prêtée à nouveau.
        $second = $pool->acquire();
        self::assertSame($client, $second->client());
        $pool->release($second);
        self::assertSame(1, $pool->idleCount());
    }

    /**
     * RFC-023 §3.2 : chaque lecture attend au plus une seconde par défaut ; `null`
     * laisse le délai de PHP à un client qui bloque plus longtemps (un `queue:work`).
     */
    #[Test]
    public function a_lease_reads_with_a_one_second_timeout_unless_told_otherwise(): void
    {
        $pool = RedisPoolFactory::create(self::REDIS);
        $lease = $pool->acquire();
        $client = $lease->client();
        self::assertInstanceOf(Redis::class, $client);
        self::assertSame(RedisPoolFactory::READ_TIMEOUT, $client->getOption(Redis::OPT_READ_TIMEOUT));
        $pool->release($lease);

        $blocking = RedisPoolFactory::create(self::REDIS, null);
        $lease = $blocking->acquire();
        $client = $lease->client();
        self::assertInstanceOf(Redis::class, $client);
        self::assertNotSame(RedisPoolFactory::READ_TIMEOUT, $client->getOption(Redis::OPT_READ_TIMEOUT));
        $blocking->release($lease);
    }

    #[Test]
    public function the_dsn_password_is_sent_with_auth(): void
    {
        // Le Redis de développement n'a pas de mot de passe : c'est AUTH qui répond.
        $pool = RedisPoolFactory::create('redis://:pas-le-bon@waffle-redis:6379');

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageMatches('/AUTH/');

        $pool->acquire();
    }
}
