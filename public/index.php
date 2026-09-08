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

// FIX-01 #9 (Beta6 audit) : le retour de load() était auparavant ignoré. DotEnv
// ne mute plus l'environnement global (durcissement Beta-1, cf. sa docblock) —
// le résultat DOIT donc être capté explicitement, sous peine que $env/$debug
// ci-dessous retombent silencieusement sur leurs valeurs par défaut, quel que
// soit le contenu réel de .env ou de l'environnement Docker/Kubernetes. Même
// précédence que AppKernelFactory::create() : le process l'emporte sur .env.
$envRegistry = array_merge(new DotEnv(path: APP_ROOT)->load(), getenv());
$env = $envRegistry[Constant::APP_ENV] ?? Constant::ENV_PROD;
$debug = filter_var($envRegistry[Constant::APP_DEBUG] ?? false, FILTER_VALIDATE_BOOL);

// 1. Contexte & assemblage.
// On délègue à la Factory la création des implémentations concrètes.
$kernel = AppKernelFactory::create(env: $env, debug: $debug);

// 2. Runtime (agnostique).
// Le runtime orchestre simplement la boucle FrankenPHP [Kernel + Request -> Emitter].
// STAB-01 : la GlobalsFactory et l'émetteur sont des instances par processus,
// injectées explicitement dans le runtime (aucun état statique partagé).
$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 500);
// SEC-03 (Beta6 audit) : racine de dépôt des uploads — permet à UploadedFile::
// moveTo() de vérifier le confinement via Assert::within() plutôt que de se fier
// uniquement à Assert::safePath() (qui ne rejette que les segments littéraux `..`).
new WaffleRuntime(new GlobalsFactory(uploadBaseDir: APP_ROOT . '/var/uploads'), new ResponseEmitter())
    ->loop(
        kernel: $kernel,
        maxRequests: $maxRequests
    )
;
