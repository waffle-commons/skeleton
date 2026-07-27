<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\WebAuthn\InMemoryChallengeStore;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Auth\WebAuthn\WebAuthnCeremony;
use Waffle\Commons\Auth\WebAuthn\WebAuthnUser;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

use function bin2hex;
use function random_bytes;

/**
 * Vitrine WebAuthn / passkeys (AXE6 / AUTH-01).
 *
 * Émission d'options des deux cérémonies, suffisante pour piloter
 * `navigator.credentials.create()` / `.get()` côté navigateur :
 *   - `POST /webauthn/register-start` : options d'attestation (enrôlement),
 *   - `POST /webauthn/assert-start`   : options d'assertion (connexion), avec le
 *     défi mémorisé contre un identifiant de cérémonie opaque (usage unique).
 *
 * La VÉRIFICATION des réponses du navigateur (`finishRegistration` /
 * `verifyAssertion`) exige une vraie réponse d'authentificateur ; elle est portée
 * par le {@see WebAuthnCeremony} et le {@see \Waffle\Commons\Auth\WebAuthn\WebAuthnAuthenticator}
 * câblés dans le pont d'authentification. Voir le rapport pour ce qui est
 * simplifié (émission d'options uniquement, pas de réponse d'authentificateur
 * synthétique).
 *
 * `#[PublicAccess]` : l'émission d'options précède toute identité (opt-out ABAC).
 */
#[Route(path: '/', name: 'webauthn_')]
final class WebAuthnController extends BaseController
{
    /**
     * Options d'enrôlement d'une nouvelle passkey.
     *
     * @throws RenderingException
     */
    #[Route(path: 'webauthn/register-start', methods: [Routing::METHOD_POST], name: 'register_start')]
    #[PublicAccess]
    public function registerStart(WebAuthnCeremony $ceremony): ResponseInterface
    {
        // Handle utilisateur opaque et stable (jamais une PII / un email).
        $user = new WebAuthnUser(id: bin2hex(random_bytes(16)), name: 'demo@waffle.dev', displayName: 'Démo Waffle');

        $options = $ceremony->startRegistration($user);

        return $this->jsonResponse(data: [
            'user_handle' => $user->id(),
            'challenge' => $options->challenge(),
            'publicKey' => $options->toArray(),
            'note' => 'À passer à navigator.credentials.create() ; persistez le défi contre la cérémonie en cours.',
        ]);
    }

    /**
     * Options de connexion (assertion), avec défi mémorisé pour le rejeu.
     *
     * @throws RenderingException
     */
    #[Route(path: 'webauthn/assert-start', methods: [Routing::METHOD_POST], name: 'assert_start')]
    #[PublicAccess]
    public function assertStart(WebAuthnCeremony $ceremony, InMemoryChallengeStore $challenges): ResponseInterface
    {
        // Connexion sans nom d'utilisateur (discoverable) : passkeys non restreintes.
        $options = $ceremony->startAuthentication(userHandle: '');

        $ceremonyId = bin2hex(random_bytes(16));
        // Mémorisé contre l'identifiant de cérémonie ; le navigateur le renverra
        // dans l'en-tête X-Wfl-Webauthn-Ceremony, que le WebAuthnAuthenticator
        // rejouera (usage unique).
        $challenges->remember($ceremonyId, $options);

        return $this->jsonResponse(data: [
            'ceremony_id' => $ceremonyId,
            'challenge' => $options->challenge(),
            'publicKey' => $options->toArray(),
            'note' => 'À passer à navigator.credentials.get() ; renvoyez l\'assertion avec l\'en-tête X-Wfl-Webauthn-Ceremony.',
        ]);
    }
}
