<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DemoService;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Commons\Routing\Attribute\Route;
use Waffle\Core\BaseController;

/**
 * This is a simple template controller to test the end-to-end
 * request/response lifecycle of the Waffle framework.
 */
#[Route(path: '/', name: 'demo_')]
final class DemoController extends BaseController
{
    /**
     * Handles requests to the root path ("/").
     */
    #[Route(path: '', name: 'index')]
    public function index(DemoService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello());
    }

    /**
     * Handles dynamic requests to "/hello/{name}".
     * This tests the router's ability to handle parameters.
     */
    #[Route(
        path: 'hello/{name}',
        name: 'hello',
        arguments: [
            new Argument(classType: 'string', paramName: 'name', required: false),
        ],
    )]
    public function hello(DemoService $service, string $name): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $name));
    }
    /**
     * Handles errors handling.
     */
    #[Route(path: 'crash', name: 'crash')]
    public function crash(): ResponseInterface
    {
        throw new \RuntimeException("Something wrong appending!");
    }
}
