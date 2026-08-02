<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\ReadDemoController;
use AppTests\AbstractTestCase;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Data\Connection\PdoConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine de lecture relationnelle `GET /read/demo` (pré-travail bench AXE 5).
 *
 * Trois invariants sont verrouillés ici :
 *   - la lecture passe par UNE requête SELECT paramétrée (marqueur `?`, jamais
 *     de concaténation de l'identifiant) sur la connexion empruntée au pool ;
 *   - un identifiant inconnu répond `{found: false, user: null}` en HTTP 200
 *     (comparabilité des benchs — statut homogène entre « trouvé » et « absent ») ;
 *   - un identifiant absent de la query string ne touche JAMAIS le pool.
 */
final class ReadDemoControllerTest extends AbstractTestCase
{
    private const string EXPECTED_SQL = 'SELECT id, email, created_at FROM users WHERE id = ?';

    private function controller(): ReadDemoController
    {
        $controller = new ReadDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    private function request(array $queryParams): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects(self::once())->method('getQueryParams')->willReturn($queryParams);

        return $request;
    }

    /**
     * Assemble la chaîne pool → connexion → PDO → statement avec la requête
     * paramétrée attendue ; `$row` pilote le résultat du fetch (ligne ou false).
     */
    private function poolReturningRow(string $id, array|false $row): RelationalConnectionPoolInterface
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([$id])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn($row);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->with(self::EXPECTED_SQL)->willReturn($statement);
        // Preuve négative : une route de LECTURE ne sollicite jamais les points
        // d'entrée d'écriture PDO.
        $pdo->expects(self::never())->method('exec');

        $connection = $this->createMock(PdoConnectionInterface::class);
        $connection->expects(self::once())->method('pdo')->willReturn($pdo);

        $pool = $this->createMock(RelationalConnectionPoolInterface::class);
        $pool->expects(self::once())->method('acquire')->willReturn($connection);

        return $pool;
    }

    #[Test]
    public function read_returns_the_user_row_when_the_id_matches(): void
    {
        $row = ['id' => '42', 'email' => 'ada@waffle.dev', 'created_at' => '2026-08-02 10:00:00'];
        $pool = $this->poolReturningRow('42', $row);

        $response = $this->controller()->read($this->request(['id' => '42']), $pool);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{found: bool, user: array{id: string, email: string, created_at: string}|null} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['found']);
        self::assertSame($row, $payload['user']);
    }

    #[Test]
    public function read_reports_not_found_with_http_200_for_bench_comparability(): void
    {
        $pool = $this->poolReturningRow('unknown', false);

        $response = $this->controller()->read($this->request(['id' => 'unknown']), $pool);

        // 200 volontaire (jamais 404) : les scénarios k6 comparent des latences
        // entre moteurs — un statut homogène évite de scinder les percentiles.
        self::assertSame(200, $response->getStatusCode());

        /** @var array{found: bool, user: null} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['found']);
        self::assertNull($payload['user']);
    }

    #[Test]
    public function read_never_touches_the_pool_when_the_id_is_missing(): void
    {
        $pool = $this->createMock(RelationalConnectionPoolInterface::class);
        $pool->expects(self::never())->method('acquire');

        $response = $this->controller()->read($this->request([]), $pool);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{found: bool, user: null} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['found']);
        self::assertNull($payload['user']);
    }

    #[Test]
    public function read_treats_an_empty_id_as_missing(): void
    {
        $pool = $this->createMock(RelationalConnectionPoolInterface::class);
        $pool->expects(self::never())->method('acquire');

        $response = $this->controller()->read($this->request(['id' => '']), $pool);

        /** @var array{found: bool, user: null} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['found']);
        self::assertNull($payload['user']);
    }
}
