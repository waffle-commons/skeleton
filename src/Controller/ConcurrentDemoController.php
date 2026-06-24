<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine du fan-out HTTP concurrent (AXE2 / ASYNC-02).
 *
 * `GET /concurrent/fan-out` résout un lot de requêtes sortantes EN PARALLÈLE via
 * {@see ConcurrentClientInterface::sendRequests()} : N requêtes se terminent en
 * gros dans le temps mur de la plus lente, au lieu de la somme des temps. Le
 * client concret (`waffle-commons/http-client`) implémente le contrat ; il reste
 * protégé par le SsrfGuard (SEC-02), donc les cibles doivent être autorisées.
 *
 * `#[PublicAccess]` : démo sans autorisation (opt-out ABAC explicite).
 */
#[Route(path: '/', name: 'concurrent_demo_')]
#[PublicAccess]
final class ConcurrentDemoController extends BaseController
{
    /** @var list<string> Cibles internes de démonstration (résolues/épinglées par le SsrfGuard). */
    private const array TARGETS = [
        'http://legacy-backend/health',
        'http://legacy-backend/version',
        'http://legacy-backend/ping',
    ];

    /**
     * @throws RenderingException
     */
    #[Route(path: 'concurrent/fan-out', methods: [Routing::METHOD_GET], name: 'fan_out')]
    public function fanOut(ConcurrentClientInterface $client, RequestFactoryInterface $requests): ResponseInterface
    {
        $batch = [];
        foreach (self::TARGETS as $url) {
            $batch[$url] = $requests->createRequest(Routing::METHOD_GET, $url);
        }

        try {
            $responses = $client->sendRequests($batch);
        } catch (ClientExceptionInterface $error) {
            // Le fan-out échoue de façon atomique (fail-fast) : on rend l'échec
            // observable sans masquer la cause (l'ErrorHandler sérialise le reste).
            return $this->jsonResponse(data: [
                'concurrent' => true,
                'ok' => false,
                'reason' => $error->getMessage(),
                'note' => 'Cibles internes injoignables hors d\'un déploiement complet — l\'API de fan-out reste démontrée.',
            ], status: 502);
        }

        $statuses = [];
        foreach ($responses as $url => $response) {
            $statuses[$url] = $response->getStatusCode();
        }

        return $this->jsonResponse(data: [
            'concurrent' => true,
            'ok' => true,
            'statuses' => $statuses,
            'note' => 'Lot résolu en parallèle via une seule boucle multi-handle (ASYNC-02).',
        ]);
    }
}
