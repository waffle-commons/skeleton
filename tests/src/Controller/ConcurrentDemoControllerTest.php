<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\ConcurrentDemoController;
use AppTests\AbstractTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine du fan-out HTTP concurrent (AXE2 / ASYNC-02).
 *
 * Les deux chemins comptent : le lot résolu (statuts rendus par cible) et
 * l'échec atomique du lot, qui doit rester observable en 502 sans masquer la
 * cause.
 */
final class ConcurrentDemoControllerTest extends AbstractTestCase
{
    private function controller(): ConcurrentDemoController
    {
        $controller = new ConcurrentDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    /**
     * Client concret : résout chaque requête du lot avec le code fourni, ou
     * échoue en bloc quand `$failure` est passé.
     */
    private function client(int $status, ?ClientExceptionInterface $failure = null): ConcurrentClientInterface
    {
        return new class($status, $failure, new ResponseFactory()) implements ConcurrentClientInterface {
            public function __construct(
                private readonly int $status,
                private readonly ?ClientExceptionInterface $failure,
                private readonly ResponseFactory $responses,
            ) {}

            /**
             * @param array<array-key, RequestInterface> $requests
             *
             * @return array<array-key, ResponseInterface>
             *
             * @throws ClientExceptionInterface
             */
            #[\Override]
            public function sendRequests(array $requests): array
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $responses = [];
                foreach (array_keys($requests) as $url) {
                    $responses[$url] = $this->responses->createResponse($this->status);
                }

                return $responses;
            }

            #[\Override]
            public function promise(RequestInterface $request): PromiseInterface
            {
                throw new LogicException('La démo de fan-out n\'utilise pas les promesses unitaires.');
            }
        };
    }

    #[Test]
    public function a_resolved_batch_reports_one_status_per_target(): void
    {
        $response = $this->controller()->fanOut($this->client(200), new RequestFactory());

        self::assertSame(200, $response->getStatusCode());

        /** @var array{concurrent: bool, ok: bool, statuses: array<string, int>, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertCount(3, $payload['statuses']);
        $health = $payload['statuses']['http://legacy-backend/health'] ?? self::fail('cible absente du lot');
        self::assertSame(200, $health);
    }

    #[Test]
    public function an_unreachable_batch_fails_atomically_with_a_502(): void
    {
        $failure = new class('cible injoignable') extends RuntimeException implements ClientExceptionInterface {};

        $response = $this->controller()->fanOut($this->client(200, $failure), new RequestFactory());

        self::assertSame(502, $response->getStatusCode());

        /** @var array{concurrent: bool, ok: bool, reason: string, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        // La cause n'est pas masquée.
        self::assertSame('cible injoignable', $payload['reason']);
    }
}
