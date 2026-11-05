<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Waffle\Commons\Contracts\Pipeline\MiddlewareStackInterface;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use Waffle\Commons\OpenApi\Middleware\ApiDocsMiddleware;

/**
 * [API-01] Documentation de développement : `GET /_docs` (Swagger UI) et
 * `GET /_docs/openapi.json` (le document OpenAPI 3.1 généré depuis les routes),
 * en mode debug UNIQUEMENT. Le document décrit toutes les routes, y compris
 * celles qu'un voter protège ; le mode debug étant refusé en production
 * (`assertNotDebugInProduction()`), ces chemins y tombent sur le 404 du routeur.
 *
 * `waffle-commons/openapi` est une dépendance de développement (`require-dev`) :
 * installée avec `--no-dev`, l'application n'a pas la classe, et la factory
 * n'ajoute rien, même en debug.
 *
 * Extraite d'AppKernelFactory pour que la branche debug ne s'ajoute pas à sa
 * complexité, déjà au plafond CPLX-04.
 */
final class ApiDocsFactory
{
    public const string TITLE = 'API du squelette Waffle';

    /**
     * En debug, ajoute le middleware de documentation à la pile, à l'endroit de
     * l'appel : avant le routage, pour que `/_docs` ne soit jamais une route.
     */
    public static function register(
        MiddlewareStackInterface $stack,
        RouterInterface $router,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        bool $debug,
    ): void {
        if (!$debug || !class_exists(ApiDocsMiddleware::class)) {
            return;
        }

        $stack->add(new ApiDocsMiddleware($router, $responseFactory, $streamFactory, self::TITLE));
    }
}
