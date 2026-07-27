<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine de l'isolation transactionnelle failsafe (AXE4 / DBAL-02).
 *
 * `POST /write/demo` est une requête d'écriture : le
 * `TransactionIsolationMiddleware` l'enveloppe dans UNE transaction empruntée au
 * pool relationnel (affinité de connexion DBAL-01). Tout `acquire()` pendant la
 * requête renvoie la MÊME connexion épinglée — donc cette action écrit dans la
 * transaction ouverte par le middleware, qui commite au retour normal et rollback
 * sur toute exception non rattrapée.
 *
 * L'instruction ci-dessous est volontairement neutre vis-à-vis du schéma
 * (`SELECT 1`) pour rester autoporteuse : en production, ce serait un INSERT /
 * UPDATE via un dépôt. Ce qui est démontré, c'est la FRONTIÈRE transactionnelle,
 * pas la requête elle-même.
 *
 * `#[PublicAccess]` : démo sans autorisation (opt-out ABAC explicite).
 */
#[Route(path: '/', name: 'write_demo_')]
final class WriteDemoController extends BaseController
{
    /**
     * @throws RenderingException
     */
    #[Route(path: 'write/demo', methods: [Routing::METHOD_POST], name: 'demo')]
    #[PublicAccess]
    public function write(RelationalConnectionPoolInterface $pool): ResponseInterface
    {
        // Connexion épinglée par le middleware pour toute la requête d'écriture :
        // l'instruction s'exécute dans SA transaction (commit/rollback gérés en amont).
        $pdo = $pool->acquire()->pdo();
        $statement = $pdo->query('SELECT 1');
        $applied = $statement !== false;

        return $this->jsonResponse(data: [
            'written' => $applied,
            'in_transaction' => $pdo->inTransaction(),
            'note' => 'Enveloppé dans une transaction unique par le TransactionIsolationMiddleware (commit au retour, rollback sur exception).',
        ]);
    }
}
