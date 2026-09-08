<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\WriteDemoController;
use AppTests\AbstractTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Data\Connection\ConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\PdoConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine de l'isolation transactionnelle failsafe (AXE4 / DBAL-02).
 *
 * Ce qui est vérifié ici est la FRONTIÈRE : l'action s'exécute sur la connexion
 * épinglée que lui tend le pool, et rapporte fidèlement si une transaction est
 * ouverte — c'est le `TransactionIsolationMiddleware` qui l'ouvre en amont.
 * SQLite en mémoire suffit : aucune base externe n'est requise.
 */
final class WriteDemoControllerTest extends AbstractTestCase
{
    private function controller(): WriteDemoController
    {
        $controller = new WriteDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    /**
     * Pool concret rendant TOUJOURS la même connexion : c'est exactement
     * l'affinité de connexion à portée requête (DBAL-01) que la démo illustre.
     */
    private function pool(PDO $pdo): RelationalConnectionPoolInterface
    {
        $lease = new class($pdo) implements PdoConnectionInterface {
            public function __construct(
                private readonly PDO $pdo,
            ) {}

            #[\Override]
            public function pdo(): PDO
            {
                return $this->pdo;
            }

            #[\Override]
            public function kind(): ConnectionKind
            {
                return ConnectionKind::Pdo;
            }

            #[\Override]
            public function isAlive(): bool
            {
                return true;
            }

            #[\Override]
            public function id(): int
            {
                return 1;
            }
        };

        return new class($lease) implements RelationalConnectionPoolInterface {
            public int $acquired = 0;

            public function __construct(
                private readonly PdoConnectionInterface $lease,
            ) {}

            #[\Override]
            public function acquire(): PdoConnectionInterface
            {
                $this->acquired++;

                return $this->lease;
            }

            #[\Override]
            public function beginRequestScope(): PdoConnectionInterface
            {
                return $this->lease;
            }

            #[\Override]
            public function endRequestScope(): void {}

            #[\Override]
            public function release(ConnectionInterface $connection): void {}
        };
    }

    #[Test]
    public function the_write_runs_on_the_pinned_connection(): void
    {
        $pool = $this->pool(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));

        $response = $this->controller()->write($pool);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{written: bool, in_transaction: bool, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['written']);
        // Hors middleware, aucune transaction n'est ouverte en amont.
        self::assertFalse($payload['in_transaction']);
    }

    #[Test]
    public function the_write_reports_the_transaction_opened_by_the_middleware(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Ce que fait le TransactionIsolationMiddleware avant l'action.
        $pdo->beginTransaction();

        $response = $this->controller()->write($this->pool($pdo));

        /** @var array{written: bool, in_transaction: bool, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['in_transaction']);

        $pdo->rollBack();
    }
}
