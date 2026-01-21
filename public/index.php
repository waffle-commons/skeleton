<?php

declare(strict_types=1);

use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Runtime\WaffleRuntime;
use App\Factory\AppKernelFactory;

require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(path: dirname(path: __DIR__)));
const APP_CONFIG = 'config';

new DotEnv(path: APP_ROOT)->load();
$env = $_ENV[Constant::APP_ENV] ?? Constant::ENV_PROD;
$debug = filter_var($_ENV[Constant::APP_DEBUG] ?? false, FILTER_VALIDATE_BOOL);

// 1. Context & Glue
// We use the Factory to create the concrete implementations
$kernel = AppKernelFactory::create(env: $env, debug: $debug);

// 2. Runtime (Agnostic)
// The runtime now just orchestrates: FrankenPHP Loop [Kernel + Request -> Emitter]
$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 500);
new WaffleRuntime(globalsFactory: AppKernelFactory::$globalsFactory)
    ->loop(
        kernel: $kernel,
        maxRequests: $maxRequests
    )
;
