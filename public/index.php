<?php

declare(strict_types=1);

use App\Factory\AppKernelFactory;
use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Waffle\Commons\Runtime\WaffleRuntime;

require_once __DIR__ . '/../vendor/autoload.php';

// __DIR__ est déjà résolu (liens symboliques compris) : son parent est la racine réelle.
// APP_ROOT reste défini pour AppKernelFactory et le kernel, qui le lisent.
$root = dirname(path: __DIR__);
define('APP_ROOT', $root);
const APP_CONFIG = 'config';

// FIX-01 #9 (Beta6 audit) : le retour de load() était auparavant ignoré. DotEnv
// ne mute plus l'environnement global (durcissement Beta-1, cf. sa docblock) —
// le résultat DOIT donc être capté explicitement, sous peine que $env/$debug
// ci-dessous retombent silencieusement sur leurs valeurs par défaut, quel que
// soit le contenu réel de .env ou de l'environnement Docker/Kubernetes. Même
// précédence que AppKernelFactory::create() : le process l'emporte sur .env.
$envRegistry = array_merge(new DotEnv(path: $root)->load(), getenv());
$env = $envRegistry[Constant::APP_ENV] ?? Constant::ENV_PROD;
$debug = filter_var($envRegistry[Constant::APP_DEBUG] ?? false, FILTER_VALIDATE_BOOL);

// 1. Contexte & assemblage.
// On délègue à la Factory la création des implémentations concrètes.
$kernel = AppKernelFactory::create(env: $env, debug: $debug);

// 2. Runtime (agnostique).
// Le runtime orchestre simplement la boucle FrankenPHP [Kernel + Request -> Emitter].
// STAB-01 : la GlobalsFactory et l'émetteur sont des instances par processus,
// injectées explicitement dans le runtime (aucun état statique partagé).
// MAX_REQUESTS : lu dans le registre d’environnement (processus > .env), comme APP_ENV,
// jamais dans une superglobale depuis le contrôleur frontal.
$maxRequests = (int) ($envRegistry['MAX_REQUESTS'] ?? 500);
// SEC-03 (Beta6 audit) : racine de dépôt des uploads — permet à UploadedFile::
// moveTo() de vérifier le confinement via Assert::within() plutôt que de se fier
// uniquement à Assert::safePath() (qui ne rejette que les segments littéraux `..`).
new WaffleRuntime(new GlobalsFactory(uploadBaseDir: $root . '/var/uploads'), new ResponseEmitter())->loop(
    kernel: $kernel,
    maxRequests: $maxRequests,
);
