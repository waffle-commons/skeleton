<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Auth\Exception\AuthenticationException;
use Waffle\Commons\Contracts\Auth\Constant as AuthConstant;
use Waffle\Commons\Contracts\Auth\UserIdentityInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Routing\Exception\RouteNotFoundException;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine du Pont d'Authentification Universel (RFC-021, paquet waffle-commons/auth).
 *
 * Met en scène, de bout en bout :
 *   - l'émission d'un JWT HS256 de démonstration (`POST /auth/demo-token`),
 *   - une route protégée lisant l'identité vérifiée (`GET /api/me`) — le
 *     middleware d'authentification a déjà validé le Bearer ou l'assertion
 *     X-Wfl-Assert-User AVANT que ce contrôleur ne s'exécute.
 */
#[Route(path: '/', name: 'auth_demo_')]
final class AuthDemoController extends BaseController
{
    /**
     * Émet un JWT HS256 de démonstration, signé avec le secret du pont.
     *
     * Usage :
     *   TOKEN=$(curl -sk -X POST https://localhost:8443/auth/demo-token | jq -r .token)
     *   curl -sk https://localhost:8443/api/me -H "Authorization: Bearer $TOKEN"
     *
     * NOTE : une vraie application délègue l'émission à un IdP (Google,
     * Keycloak…) via le client OAuth2/OIDC du composant auth — cette route
     * n'existe que pour rendre la démo autoporteuse, sans IdP externe.
     *
     * @throws RenderingException
     */
    // `#[PublicAccess]` est indispensable — c'est l'action qui délivre le jeton,
    // elle ne peut donc pas en exiger un. Mais elle SIGNE avec le secret de
    // production du pont : laissée joignable en production, elle serait une
    // fabrique de jetons anonyme. Elle est donc refusée hors développement
    // (voir le garde ci-dessous), et une vraie application supprime purement et
    // simplement cette route au profit de son IdP.
    #[Route(path: 'auth/demo-token', methods: [Routing::METHOD_POST], name: 'token')]
    #[PublicAccess]
    public function demoToken(ConfigInterface $config): ResponseInterface
    {
        // Fail-closed : hors `dev`, cette route n'existe pas. Le 404 (plutôt
        // qu'un 403) évite de révéler qu'un émetteur de jetons est présent dans
        // le binaire déployé.
        if ($config->getString('waffle.env') !== Constant::ENV_DEV) {
            throw new RouteNotFoundException('No route found for "POST /auth/demo-token".');
        }

        $secret = (string) $config->getString('waffle.auth.secret');
        $now = time();

        $claims = [
            'sub' => 'demo-user',
            'email' => 'demo@waffle.dev',
            'roles' => ['ROLE_DEMO'],
            'iss' => $config->getString('waffle.auth.jwt.issuer') ?? 'https://waffle-dev.local',
            'aud' => $config->getString('waffle.auth.jwt.audience') ?? 'waffle-skeleton',
            'iat' => $now,
            'exp' => $now + 300,
        ];

        // Assemblage JWS compact : base64url(en-tête).base64url(payload).base64url(HMAC).
        $encode = static fn(string $bin): string => rtrim(
            strtr(base64_encode($bin), from: '+/', to: '-_'),
            characters: '=',
        );
        $signingInput =
            $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            . '.'
            . $encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $token = $signingInput . '.' . $encode(hash_hmac('sha256', $signingInput, $secret, binary: true));

        return $this->jsonResponse(data: [
            'token' => $token,
            'type' => 'Bearer',
            'expires_in' => 300,
            'hint' => 'curl -sk https://localhost:8443/api/me -H "Authorization: Bearer <token>"',
        ]);
    }

    /**
     * Route protégée : renvoie l'identité vérifiée par le pont.
     *
     * Le middleware d'authentification (RFC-021 §3.2) a publié l'identité en
     * attribut `_auth_identity` ; son absence signifie « requête anonyme » et
     * cette route répond alors 401 — fail-closed, sans repli silencieux.
     *
     * @throws AuthenticationException Si aucune identité vérifiée n'est présente (401).
     * @throws RenderingException
     */
    // `#[PublicAccess]` lève la garde d'AUTORISATION (aucun #[Voter] à évaluer) ;
    // l'AUTHENTIFICATION reste appliquée par l'action elle-même, qui lève une
    // AuthenticationException (401) sans identité. Sans cet opt-out, le
    // SecureContainer répondait 403 avant même d'exécuter ce contrôle — la démo
    // du pont d'authentification était donc inatteignable, jeton valide ou non.
    #[Route(path: 'api/me', methods: [Routing::METHOD_GET], name: 'me')]
    #[PublicAccess]
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(AuthConstant::REQUEST_ATTRIBUTE);
        if (!$identity instanceof UserIdentityInterface) {
            throw new AuthenticationException(
                'Authentification requise : fournissez un Bearer JWT ou une assertion de passerelle.',
            );
        }

        return $this->jsonResponse(data: [
            'subject' => $identity->subject,
            'email' => $identity->email,
            'roles' => $identity->roles,
            'authenticated_by' => 'universal-authentication-bridge',
        ]);
    }
}
