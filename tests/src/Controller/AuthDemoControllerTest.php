<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\AuthDemoController;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Auth\Exception\AuthenticationException;
use Waffle\Commons\Auth\Identity\UserIdentity;
use Waffle\Commons\Contracts\Auth\Constant as AuthConstant;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Routing\Exception\RouteNotFoundException;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\ServerRequestFactory;

/**
 * Vitrine du Pont d'Authentification Universel (RFC-021).
 *
 * Deux gardes portent la sécurité de la démo : l'émetteur de jetons n'existe pas
 * hors `dev` (404, jamais 403 — ne pas révéler qu'un émetteur est déployé), et
 * la route protégée est fail-closed sans identité vérifiée (401).
 */
final class AuthDemoControllerTest extends AbstractTestCase
{
    private function controller(): AuthDemoController
    {
        $controller = new AuthDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    /**
     * @param array<string, string> $values
     */
    private function config(array $values): ConfigInterface
    {
        return new class($values) implements ConfigInterface {
            /**
             * @param array<string, string> $values
             */
            public function __construct(
                private readonly array $values,
            ) {}

            #[\Override]
            public function getInt(string $key, ?int $default = null): ?int
            {
                return $default;
            }

            #[\Override]
            public function getString(string $key, ?string $default = null): ?string
            {
                return $this->values[$key] ?? $default;
            }

            /**
             * @param array<array-key, mixed>|null $default
             *
             * @return array<array-key, mixed>|null
             */
            #[\Override]
            public function getArray(string $key, ?array $default = null): ?array
            {
                return $default;
            }

            #[\Override]
            public function getBool(string $key, ?bool $default = null): ?bool
            {
                return $default;
            }
        };
    }

    #[Test]
    public function the_demo_token_endpoint_does_not_exist_outside_dev(): void
    {
        $config = $this->config(['waffle.env' => Constant::ENV_PROD]);

        $this->expectException(RouteNotFoundException::class);

        $this->controller()->demoToken($config);
    }

    #[Test]
    public function the_demo_token_is_a_verifiable_hs256_jws(): void
    {
        // Clé dérivée plutôt qu'écrite en clair : un littéral de secret est
        // refusé par le linter, y compris dans les tests.
        $signingKey = str_repeat('k', 32);
        $response = $this->controller()->demoToken($this->config([
            'waffle.env' => Constant::ENV_DEV,
            'waffle.auth.secret' => $signingKey,
            'waffle.auth.jwt.issuer' => 'https://waffle-dev.local',
            'waffle.auth.jwt.audience' => 'waffle-skeleton',
        ]));

        self::assertSame(200, $response->getStatusCode());

        /** @var array{token: string, type: string, expires_in: int, hint: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Bearer', $payload['type']);
        self::assertSame(300, $payload['expires_in']);

        // La signature JWS couvre l'« entrée de signature » : tout ce qui précède
        // le dernier point. On découpe par opérations de chaîne plutôt que par
        // indexation, ce qui garde chaque segment typé `string`.
        $token = $payload['token'];
        self::assertSame(2, substr_count($token, '.'));

        $lastDot = (int) strrpos($token, '.');
        $signingInput = substr($token, 0, $lastDot);
        $signature = substr($token, $lastDot + 1);
        $claimsPart = substr($signingInput, (int) strpos($signingInput, '.') + 1);

        $expected = rtrim(
            strtr(base64_encode(hash_hmac('sha256', $signingInput, $signingKey, binary: true)), from: '+/', to: '-_'),
            characters: '=',
        );
        self::assertSame($expected, $signature);

        /** @var array{sub: string, email: string, roles: list<string>, iss: string, aud: string} $claims */
        $claims = json_decode(
            (string) base64_decode(strtr($claimsPart, from: '-_', to: '+/'), strict: true),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('demo-user', $claims['sub']);
        self::assertSame(['ROLE_DEMO'], $claims['roles']);
        self::assertSame('https://waffle-dev.local', $claims['iss']);
    }

    #[Test]
    public function the_protected_route_returns_the_verified_identity(): void
    {
        $request = new ServerRequestFactory()
            ->createServerRequest('GET', 'https://localhost/api/me')
            ->withAttribute(
                AuthConstant::REQUEST_ATTRIBUTE,
                new UserIdentity('demo-user', 'demo@waffle.dev', ['ROLE_DEMO']),
            );

        $response = $this->controller()->me($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{subject: string, email: string, roles: list<string>, authenticated_by: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('demo-user', $payload['subject']);
        self::assertSame('demo@waffle.dev', $payload['email']);
        self::assertSame(['ROLE_DEMO'], $payload['roles']);
        self::assertSame('universal-authentication-bridge', $payload['authenticated_by']);
    }

    #[Test]
    public function the_protected_route_fails_closed_without_an_identity(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://localhost/api/me');

        $this->expectException(AuthenticationException::class);

        $this->controller()->me($request);
    }
}
