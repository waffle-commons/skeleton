<?php

declare(strict_types=1);

namespace AppTests\Health;

use App\Factory\AppKernelFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Http\Factory\ServerRequestFactory;

/**
 * Sondes d'orchestrateur (OPS-01, RFC-023) servies par le kernel réel : /healthz
 * répond toujours, /readyz rend compte du pool relationnel exigé par
 * `waffle.health.required`, et les deux passent avant le contrôle d'hôte (la sonde
 * kubelet porte l'IP du pod en Host).
 *
 * La configuration de test pointe la base sur le port 1 de 127.0.0.1, où rien
 * n'écoute : le pool exigé est toujours injoignable, et /readyz doit le dire (503).
 */
final class ProbeEndpointsTest extends AbstractTestCase
{
    private function handle(string $uri, string $remoteAddress): ResponseInterface
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_DEV);
        $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: true);
        $kernel->boot()->configure();

        return $kernel->handle(new ServerRequestFactory()->createServerRequest(method: 'GET', uri: $uri, serverParams: [
            'REMOTE_ADDR' => $remoteAddress,
        ]));
    }

    #[Test]
    public function liveness_answers_ok_even_with_the_pod_ip_as_host(): void
    {
        $response = $this->handle('http://10.1.2.3/healthz', '10.1.2.3');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    #[Test]
    public function readiness_fails_closed_on_an_unreachable_required_pool(): void
    {
        $response = $this->handle('http://localhost/readyz', '127.0.0.1');

        self::assertSame(503, $response->getStatusCode());
        $report = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame([], $report['missing'] ?? null);
        $checks = $report['checks'] ?? null;
        self::assertIsArray($checks);
        $pool = $checks['db.pool'] ?? null;
        self::assertIsArray($pool);
        self::assertTrue($pool['critical'] ?? null);
        self::assertSame('down', $pool['status'] ?? null);
        // Cache « array » en configuration de test : aucune sonde de cache enregistrée.
        self::assertArrayNotHasKey('cache', $checks);
    }

    #[Test]
    public function readiness_hides_the_report_from_other_addresses(): void
    {
        $response = $this->handle('http://10.1.2.3/readyz', '203.0.113.9');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"unavailable"}', (string) $response->getBody());
    }

    #[Test]
    public function any_other_route_still_meets_the_host_check(): void
    {
        $response = $this->handle('http://10.1.2.3/reactive/status', '10.1.2.3');

        self::assertSame(400, $response->getStatusCode());
    }
}
