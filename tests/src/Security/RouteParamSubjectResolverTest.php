<?php

declare(strict_types=1);

namespace AppTests\Security;

use App\Security\RouteParamSubjectResolver;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Http\Factory\ServerRequestFactory;

/**
 * Verrouille le contrat du résolveur de sujet minimal du template (SEC-05) :
 * les paramètres de la route appariée (attribut PSR-7 `_params`, posé par la
 * CoreRoutingMiddleware) deviennent le sujet transmis aux voters ; sans
 * paramètres exploitables, null — les voters votent alors sur la requête.
 */
final class RouteParamSubjectResolverTest extends AbstractTestCase
{
    private function request(string $path): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();

        return $factory->createServerRequest('GET', $path);
    }

    #[Test]
    public function route_params_become_the_subject(): void
    {
        $request = $this->request('/hello/Ada')->withAttribute('_params', ['name' => 'Ada']);

        $resolver = new RouteParamSubjectResolver();

        self::assertSame(['name' => 'Ada'], $resolver->resolve($request));
    }

    #[Test]
    public function request_without_the_attribute_resolves_to_null(): void
    {
        $resolver = new RouteParamSubjectResolver();

        self::assertNull($resolver->resolve($this->request('/')));
    }

    #[Test]
    public function empty_params_resolve_to_null(): void
    {
        // Route appariée sans placeholder : `_params` vaut [] — le résolveur
        // retourne null (aucune ressource portée) ; comportement verrouillé.
        $request = $this->request('/')->withAttribute('_params', []);

        $resolver = new RouteParamSubjectResolver();

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function non_array_attribute_resolves_to_null(): void
    {
        // Attribut corrompu (non-tableau) : même repli fail-safe vers null.
        $request = $this->request('/')->withAttribute('_params', 'pas-un-tableau');

        $resolver = new RouteParamSubjectResolver();

        self::assertNull($resolver->resolve($request));
    }
}
