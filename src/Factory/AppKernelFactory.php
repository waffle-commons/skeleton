<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Http\ResponseEmitterInterface;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Security;
use App\Kernel;

/**
 * The Glue Code: Assembles the application components.
 */
final class AppKernelFactory
{
    private static GlobalsFactory $globalsFactory;

    /**
     * Creates the fully assembled Kernel.
     */
    public static function create(string $env = Constant::ENV_PROD, bool $debug = false): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;
        $rootConfig = $root . DIRECTORY_SEPARATOR . APP_CONFIG;

        // 1. Instantiate the concrete Container (from waffle-commons/container)
        $container = new Container();

        // Register PSR-17 Factory (Required for Controllers AND ErrorHandler)
        $responseFactory = new ResponseFactory();
        if (class_exists(ResponseFactory::class)) {
            $container->set(ResponseFactoryInterface::class, $responseFactory);
        }

        // 2. Instantiate the concrete Config (from waffle-commons/config)
        $config = new Config(
            configDir: $rootConfig,
            environment: $env,
        );
        // Prepare GlobalsFactory for trusted_hosts configuration
        $trustedHosts = $config->getArray(key: 'waffle.trusted_hosts');
        self::$globalsFactory = new GlobalsFactory(trustedHosts: $trustedHosts);

        // 3. Instantiate Security (from waffle-commons/security)
        $security = new Security($config);

        // 4. Wrap the container with Security Decorator
        $secureContainer = new SecureContainer($container, $security);

        // 5. Instantiate the Pipeline Middleware
        $stack = new MiddlewareStack();

        // We create the renderer and the middleware.
        // It must be PREPENDED to catch errors from all subsequent middlewares (Routing, Security, Dispatcher).
        $errorRenderer = new JsonErrorRenderer($responseFactory, $debug);
        $errorHandler = new ErrorHandlerMiddleware($errorRenderer);

        $stack->prepend($errorHandler);

        // 6. Instantiate the Kernel
        $kernel = new Kernel();

        // 7. Instantiate and Boot Router
        $controllersPath = $config->getString('waffle.paths.controllers');
        if (is_string($controllersPath)) {
            // Instantiate Router
            $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath);
            $router->boot($secureContainer);

            // Create the Bridge Middleware and add it to the Stack
            // This connects the Router to the Pipeline
            $routingMiddleware = new CoreRoutingMiddleware($router);
            $stack->add($routingMiddleware);
        }

        // 8. Inject Dependencies
        if (method_exists($kernel, 'setConfiguration')) {
            $kernel->setConfiguration($config);
        }

        if (method_exists($kernel, 'setSecurity')) {
            $kernel->setSecurity($security);
        }

        if (method_exists($kernel, 'setContainerImplementation')) {
            $kernel->setContainerImplementation($secureContainer);
        }

        if (method_exists($kernel, 'setMiddlewareStack')) {
            $kernel->setMiddlewareStack($stack);
        }

        return $kernel;
    }

    /**
     * Creates the PSR-7 Request from globals.
     */
    public static function createRequest(): ServerRequestInterface
    {
        return self::$globalsFactory->createFromGlobals();
    }

    /**
     * Creates the Response Emitter.
     */
    public static function createEmitter(): ResponseEmitterInterface
    {
        return new ResponseEmitter();
    }
}
