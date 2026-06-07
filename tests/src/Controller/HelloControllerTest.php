<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\HelloController;
use App\Dto\HelloInput;
use App\Service\DemoService;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Waffle\Commons\Http\Factory\ResponseFactory;

final class HelloControllerTest extends AbstractTestCase
{
    private function controller(): HelloController
    {
        $controller = new HelloController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    #[Test]
    public function index_returns_the_default_greeting(): void
    {
        $response = $this->controller()->index(new DemoService());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString('{"message":"Hello from Waffle!"}', (string) $response->getBody());
    }

    #[Test]
    public function hello_greets_the_path_parameter(): void
    {
        $response = $this->controller()->hello(new DemoService(), 'Ada');

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"message":"Hello Ada!"}', (string) $response->getBody());
    }

    #[Test]
    public function greet_renders_the_hydrated_and_validated_dto(): void
    {
        $response = $this->controller()->greet(new DemoService(), new HelloInput(name: 'Ada'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"message":"Hello Ada!"}', (string) $response->getBody());
    }

    #[Test]
    public function catch_all_reports_the_intercepted_path(): void
    {
        $response = $this->controller()->catchAll('legacy/api/users');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{gateway: string, intercepted_path: string, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Waffle', $payload['gateway']);
        self::assertSame('/legacy/api/users', $payload['intercepted_path']);
    }

    #[Test]
    public function crash_throws_for_the_error_handler_to_intercept(): void
    {
        $this->expectException(RuntimeException::class);

        $this->controller()->crash();
    }
}
