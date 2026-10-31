<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\AppKernelFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Http\Factory\ServerRequestFactory;

/**
 * [API-01] La documentation de développement, à travers le vrai kernel : servie en
 * debug, absente sinon — la requête retombe alors sur le routeur, ici sur la route
 * attrape-tout de démonstration.
 */
final class ApiDocsFactoryTest extends AbstractTestCase
{
    private function handle(string $uri, bool $debug): ResponseInterface
    {
        putenv(Constant::APP_ENV . '=' . Constant::ENV_DEV);
        $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: $debug);
        $kernel->boot()->configure();

        return $kernel->handle(new ServerRequestFactory()->createServerRequest(method: 'GET', uri: $uri, serverParams: [
            'REMOTE_ADDR' => '127.0.0.1',
        ]));
    }

    #[Test]
    public function in_debug_the_page_and_the_document_are_served(): void
    {
        $page = $this->handle('http://localhost/_docs', true);

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('swagger-ui-bundle.js', (string) $page->getBody());
        self::assertStringContainsString('API du squelette Waffle', (string) $page->getBody());
        self::assertStringContainsString("default-src 'none'", $page->getHeaderLine('Content-Security-Policy'));

        $document = $this->handle('http://localhost/_docs/openapi.json', true);
        self::assertSame(200, $document->getStatusCode());
        $decoded = json_decode((string) $document->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('3.1.1', $decoded['openapi'] ?? null);
        $paths = $decoded['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertArrayHasKey('/hello/{name}', $paths);
    }

    #[Test]
    public function outside_debug_neither_is_served(): void
    {
        foreach (['/_docs', '/_docs/openapi.json'] as $path) {
            $response = $this->handle('http://localhost' . $path, false);
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($decoded, $path);
            self::assertArrayNotHasKey('openapi', $decoded, $path);
            self::assertSame($path, $decoded['intercepted_path'] ?? null, $path);
        }
    }
}
