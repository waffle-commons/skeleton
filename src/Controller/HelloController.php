<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\HelloInput;
use App\Service\DemoService;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Commons\Routing\Attribute\Route;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Demonstrates the Beta-1 request lifecycle end to end:
 *   - scalar route parameters,
 *   - native `#[Dto]` hydration + Property-Hook validation,
 *   - exception interception by the ErrorHandlerMiddleware,
 *   - a low-priority catch-all that models the EcoShield gateway proxy hand-off.
 */
#[Route(path: '/', name: 'hello_')]
final class HelloController extends BaseController
{
    /**
     * Root smoke endpoint: GET /.
     *
     * @throws RenderingException
     */
    #[Route(path: '', name: 'index')]
    public function index(DemoService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello());
    }

    /**
     * Scalar path-parameter demonstration: GET /hello/{name}.
     * The `{name}` segment is injected as a plain string by the argument resolver.
     *
     * @throws RenderingException
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
     * Native DTO hydration demonstration: POST /greet with a JSON body
     * `{"name": "Ada"}`.
     *
     * The ControllerArgumentResolver decodes the parsed body, hydrates
     * {@see HelloInput}, and the DTO's Property Hook validates the value. An
     * invalid `name` throws and is rendered as an RFC 7807 `422` by the
     * ErrorHandlerMiddleware — without a single line of validation code here.
     *
     * @throws RenderingException
     */
    #[Route(path: 'greet', name: 'greet')]
    public function greet(DemoService $service, HelloInput $input): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $input->name));
    }

    /**
     * Error-handling demonstration: GET /crash. Any thrown exception is
     * intercepted and rendered as a structured JSON error by the middleware.
     */
    #[Route(path: 'crash', name: 'crash')]
    public function crash(): ResponseInterface
    {
        throw new RuntimeException('Something went wrong while greeting!');
    }

    /**
     * Catch-all gateway hand-off (priority -1000 ⇒ evaluated last, after every
     * explicit route). In the EcoShield gateway this is where an unmatched
     * request would be transparently proxied to the legacy backend; the skeleton
     * returns a placeholder so the interception point is observable.
     *
     * @throws RenderingException
     */
    #[Route(path: '{path:.*}', name: 'catch_all', priority: -1000)]
    public function catchAll(string $path): ResponseInterface
    {
        return $this->jsonResponse(
            data: [
                'gateway' => 'EcoShield',
                'intercepted_path' => '/' . $path,
                'note' => 'Unmatched route — in production this would be proxied to the legacy backend.',
            ],
        );
    }
}
