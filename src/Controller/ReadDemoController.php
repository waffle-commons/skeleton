<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine de lecture relationnelle sur le pool de connexions (RFC-022 / DBAL-01).
 *
 * `GET /read/demo?id=<id>` emprunte une connexion au pool relationnel et exécute
 * UNE requête SELECT paramétrée (jamais de concaténation : l'identifiant transite
 * par un marqueur `?`, le driver l'échappe). La requête étant une lecture (GET),
 * le TransactionIsolationMiddleware ne l'enveloppe pas : la connexion empruntée
 * est rendue au pool par le reset de fin de requête (worker-safe).
 *
 * Un identifiant absent ou inconnu répond `{found: false, user: null}` en
 * HTTP 200 (et non 404) : les scénarios de bench comparent des latences entre
 * moteurs, un statut homogène évite de mélanger les percentiles des chemins
 * « trouvé » et « absent ».
 *
 * `#[PublicAccess]` : démo sans autorisation (opt-out ABAC explicite). Pas de
 * CSRF : lecture idempotente (GET), aucun état muté.
 */
#[Route(path: '/', name: 'read_demo_')]
final class ReadDemoController extends BaseController
{
    /**
     * @throws RenderingException
     */
    #[Route(path: 'read/demo', methods: [Routing::METHOD_GET], name: 'demo')]
    #[PublicAccess]
    public function read(ServerRequestInterface $request, RelationalConnectionPoolInterface $pool): ResponseInterface
    {
        $id = $request->getQueryParams()['id'] ?? null;

        // Identifiant absent ou vide : réponse « non trouvé » sans toucher au
        // pool — inutile d'emprunter une connexion pour ne rien chercher.
        if (!is_string($id) || $id === '') {
            return $this->jsonResponse(data: ['found' => false, 'user' => null]);
        }

        $pdo = $pool->acquire()->pdo();
        $statement = $pdo->prepare('SELECT id, created_at FROM users WHERE id = ?');

        $user = null;
        if ($statement !== false && $statement->execute([$id])) {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $user = $row;
            }
        }

        return $this->jsonResponse(data: ['found' => $user !== null, 'user' => $user]);
    }
}
