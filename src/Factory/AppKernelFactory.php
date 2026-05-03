<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\EventDispatcher\EventDispatcherInterface;
use Waffle\Commons\Contracts\EventDispatcher\EventListenerInterface;
use Waffle\Commons\Contracts\Http\ResponseEmitterInterface;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\EventDispatcher\Dispatcher\EventDispatcher;
use Waffle\Commons\EventDispatcher\Provider\ListenerProvider;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Log\Enum\LogChannel;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Pipeline\Middleware\SecureHeadersMiddleware;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Middleware\SecurityMiddleware;
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
        $errorLogger = new StreamLogger();
        $errorHandler = new ErrorHandlerMiddleware(
            renderer: $errorRenderer,
            logger: $errorLogger,
        );

        $stack->prepend($errorHandler);

        // 5b. Event Dispatcher setup
        $listenerProvider = new ListenerProvider();
        $eventDispatcher = new EventDispatcher($listenerProvider);

        // Auto-discover listeners from EventListener directory
        $eventListenersPath = $config->getString('waffle.paths.event_listeners');
        if (is_dir($eventListenersPath)) {
            self::discoverAndRegisterListeners($listenerProvider, $eventListenersPath);
        }

        // 6. Instantiate the Kernel
        $kernelLogger = new StreamLogger(channel: LogChannel::CORE);
        $kernel = new Kernel(logger: $kernelLogger);
        $kernel->setEventDispatcher($eventDispatcher);

        // 7. Instantiate and Boot Router
        $controllersPath = $config->getString('waffle.paths.controllers');
        if (is_string($controllersPath)) {
            // Instantiate Router
            $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath);
            $router->boot($secureContainer);

            // Create the Bridge Middleware and add it to the Stack
            // This connects the Router to the Pipeline
            $routingMiddleware = new CoreRoutingMiddleware($router);
            $secureLogger = new StreamLogger(channel: LogChannel::SECURITY);
            $secureMiddleware = new SecurityMiddleware(
                secureContainer: $secureContainer,
                logger: $secureLogger,
            );
            $stack->add($routingMiddleware);
            $stack->add($secureMiddleware);
            $stack->add(new SecureHeadersMiddleware());
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

    /**
     * Auto-discovers and registers event listeners from a directory.
     */
    private static function discoverAndRegisterListeners(ListenerProvider $provider, string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false || !str_contains($content, 'AsEventListener')) {
                continue;
            }

            $fqcn = self::extractClassName($file->getPathname());
            if ($fqcn === '' || !class_exists($fqcn)) {
                continue;
            }

            $instance = new $fqcn();

            if ($instance instanceof EventListenerInterface) {
                $provider->register($instance);
            }
        }
    }

    /**
     * Extracts the fully qualified class name from a PHP file.
     */
    private static function extractClassName(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return '';
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $class = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                while (++$i < $count) {
                    if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                        break;
                    }
                    if (is_array($tokens[$i])) {
                        $namespace .= $tokens[$i][1];
                    }
                }
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $j = $i;
                while (++$j < $count) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break 2;
                    }
                    if ($tokens[$j] === '{') {
                        break;
                    }
                }
            }
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }
}
