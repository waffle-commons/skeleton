<?php

declare(strict_types=1);

use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Waffle\Commons\Runtime\WaffleRuntime;
use App\Factory\AppKernelFactory;

require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(path: dirname(path: __DIR__)));
const APP_CONFIG = 'config';

new DotEnv(path: APP_ROOT)->load();
$env = $_ENV[Constant::APP_ENV] ?? Constant::ENV_PROD;
$debug = filter_var($_ENV[Constant::APP_DEBUG] ?? false, FILTER_VALIDATE_BOOL);

// 1. Contexte & assemblage.
// On délègue à la Factory la création des implémentations concrètes.
$kernel = AppKernelFactory::create(env: $env, debug: $debug);

// 2. Runtime (agnostique).
// Le runtime orchestre simplement la boucle FrankenPHP [Kernel + Request -> Emitter].
// STAB-01 : la GlobalsFactory et l'émetteur sont des instances par processus,
// injectées explicitement dans le runtime (aucun état statique partagé).
$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 500);
new WaffleRuntime(new GlobalsFactory(), new ResponseEmitter())
    ->loop(
        kernel: $kernel,
        maxRequests: $maxRequests
    )
;
