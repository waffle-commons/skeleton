<?php

declare(strict_types=1);

namespace App\Factory;

use Waffle\Commons\Contracts\Pipeline\MiddlewareStackInterface;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Commons\Telemetry\Middleware\ProfilerHeadersMiddleware;
use Waffle\Commons\Telemetry\Profiler\QueryCountingTracer;

/**
 * [DXP-01] Profileur de développement : les en-têtes `X-Waffle-Time`,
 * `X-Waffle-Memory`, `X-Waffle-Queries` et `Server-Timing` sur chaque réponse,
 * en mode debug UNIQUEMENT. Ces en-têtes exposent l'intérieur de l'application ;
 * le mode debug étant refusé en production (`assertNotDebugInProduction()`), ils
 * n'atteignent jamais une pile de production (RFC-018 §4).
 *
 * Extraite d'AppKernelFactory pour que les deux branches debug ne s'ajoutent pas
 * à sa complexité, déjà au plafond CPLX-04.
 */
final class ProfilerFactory
{
    /**
     * En debug, enveloppe le traceur dans un traceur qui compte les requêtes en
     * base de la requête HTTP courante (les spans `waffle.db.query` des dépôts) ;
     * sinon, le rend tel quel.
     */
    public static function tracer(TracerInterface $tracer, bool $debug): TracerInterface
    {
        return $debug ? new QueryCountingTracer($tracer) : $tracer;
    }

    /**
     * En debug, ajoute le middleware d'en-têtes en tête de pile — le plus
     * extérieur, pour que le temps couvre tout le pipeline et que les réponses
     * d'erreur portent aussi les en-têtes. Sans effet hors debug.
     */
    public static function register(MiddlewareStackInterface $stack, TracerInterface $tracer, bool $debug): void
    {
        if (!$debug) {
            return;
        }

        $stack->prepend(new ProfilerHeadersMiddleware($tracer instanceof QueryCountingTracer ? $tracer : null));
    }
}
