<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\HelloInput;
use App\Service\DemoService;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine du cycle de vie d'une requête Beta-1, de bout en bout :
 *   - paramètres de route scalaires,
 *   - hydratation native d'un `#[Dto]` + validation par Property Hook,
 *   - interception d'exception par l'ErrorHandlerMiddleware,
 *   - route catch-all à priorité négative simulant le hand-off vers la
 *     passerelle Waffle (proxy vers le backend hérité).
 */
#[Route(path: '/', name: 'hello_')]
final class HelloController extends BaseController
{
    /**
     * Endpoint racine : GET /.
     *
     * `#[PublicAccess]` : routes de démo/bench publiques sans voter — l'ABAC
     * fail-closed (SecureContainer) renverrait sinon un 403 à travers le
     * pipeline réel, alors que ces vitrines n'exigent aucune autorisation.
     *
     * @throws RenderingException
     */
    #[Route(path: '', name: 'index')]
    #[PublicAccess]
    public function index(DemoService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello());
    }

    /**
     * Démonstration d'un paramètre de chemin scalaire : GET /hello/{name}.
     * Le segment `{name}` est injecté tel quel par le resolver d'arguments.
     *
     * @throws RenderingException
     */
    #[Route(path: 'hello/{name}', name: 'hello', arguments: [
        new Argument(classType: 'string', paramName: 'name', required: false),
    ])]
    #[PublicAccess]
    public function hello(DemoService $service, string $name): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $name));
    }

    /**
     * Démonstration d'hydratation native d'un DTO : POST /greet avec un corps
     * JSON `{"name": "Ada"}`.
     *
     * Le ControllerArgumentResolver décode le corps parsé, hydrate
     * {@see HelloInput} et le Property Hook valide la valeur. Un `name` invalide
     * lève une `ValidationException` que l'ErrorHandlerMiddleware sérialise en
     * RFC 7807 « 422 » — sans une seule ligne de validation dans le contrôleur.
     *
     * @throws RenderingException
     */
    // `methods` est explicite : sans lui, la route retombe sur le défaut
    // GET/HEAD/OPTIONS et le POST décrit ci-dessus répond 405 — l'hydratation du
    // DTO exige pourtant un corps de requête, donc POST est la seule méthode
    // cohérente avec le contrat de cette action.
    #[Route(path: 'greet', methods: ['POST'], name: 'greet')]
    #[PublicAccess]
    public function greet(DemoService $service, HelloInput $input): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $input->name));
    }

    /**
     * Démonstration de l'interception d'erreurs : GET /crash. N'importe quelle
     * exception levée est interceptée puis rendue en JSON structuré par le
     * middleware d'erreur.
     */
    // Vitrine publique : l'ABAC est fail-closed, donc une action sans #[Voter]
    // est refusée (403) avant d'atteindre la démonstration d'erreur.
    #[Route(path: 'crash', name: 'crash')]
    #[PublicAccess]
    public function crash(): ResponseInterface
    {
        throw new RuntimeException('Quelque chose s\'est mal passé pendant la salutation !');
    }

    /**
     * Hand-off catch-all vers la passerelle (priorité -1000 ⇒ évaluée en dernier,
     * après toutes les routes explicites). Dans une passerelle Waffle, c'est
     * ici qu'une requête non résolue serait transmise au backend hérité ; le
     * skeleton retourne un témoin JSON pour rendre le point d'interception
     * observable.
     *
     * `#[PublicAccess]` : c'est cette route qui sert tout GET non résolu (dont
     * la sonde `/health`) — sans l'attribut, l'ABAC fail-closed répondrait 403
     * à ces chemins de démo/bench publics.
     *
     * @throws RenderingException
     */
    #[Route(path: '{path:.*}', name: 'catch_all', priority: -1000)]
    #[PublicAccess]
    public function catchAll(string $path): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'gateway' => 'Waffle',
            'intercepted_path' => '/' . $path,
            'note' => 'Route inconnue — en production, cette requête serait transmise au backend hérité via la passerelle.',
        ]);
    }
}
