<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\HelloInput;
use App\Service\DemoService;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Commons\Routing\Attribute\Route;
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
     * @throws RenderingException
     */
    #[Route(path: '', name: 'index')]
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
    #[Route(path: 'greet', name: 'greet')]
    public function greet(DemoService $service, HelloInput $input): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $input->name));
    }

    /**
     * Démonstration de l'interception d'erreurs : GET /crash. N'importe quelle
     * exception levée est interceptée puis rendue en JSON structuré par le
     * middleware d'erreur.
     */
    #[Route(path: 'crash', name: 'crash')]
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
     * @throws RenderingException
     */
    #[Route(path: '{path:.*}', name: 'catch_all', priority: -1000)]
    public function catchAll(string $path): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'gateway' => 'Waffle',
            'intercepted_path' => '/' . $path,
            'note' => 'Route inconnue — en production, cette requête serait transmise au backend hérité via la passerelle.',
        ]);
    }
}
