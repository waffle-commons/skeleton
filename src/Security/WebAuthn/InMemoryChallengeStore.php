<?php

declare(strict_types=1);

namespace App\Security\WebAuthn;

use Waffle\Commons\Auth\WebAuthn\WebAuthnChallengeStoreInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\AssertionOptionsInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * Magasin à usage unique des options d'assertion émises au début d'une cérémonie
 * de connexion WebAuthn — vitrine (AXE6 / AUTH-01).
 *
 * Comme le dépôt d'identifiants, c'est la moitié AVEC état, fournie par
 * l'application : une vraie application l'adosse à un cache / une session. Cette
 * démo le garde en mémoire processus. Le défi minté au démarrage de la connexion
 * (`/webauthn/assert-start`) est conservé contre un identifiant de cérémonie
 * opaque, puis rejoué — et consommé — quand le navigateur renvoie son assertion.
 *
 * Worker-safety : l'état à portée processus impose {@see ResettableInterface}
 * DIRECTEMENT (scan DIAG-02) ; le kernel le vide à chaque boucle worker.
 */
final class InMemoryChallengeStore implements WebAuthnChallengeStoreInterface, ResettableInterface
{
    /** @var array<string, AssertionOptionsInterface> Indexé par identifiant de cérémonie. */
    private array $pending = [];

    /**
     * Mémorise les options émises contre un identifiant de cérémonie. Étape
     * d'émission, côté application (le contrat n'expose que la consommation).
     */
    public function remember(string $ceremonyId, AssertionOptionsInterface $options): void
    {
        $this->pending[$ceremonyId] = $options;
    }

    #[\Override]
    public function take(string $ceremonyId): ?AssertionOptionsInterface
    {
        $options = $this->pending[$ceremonyId] ?? null;
        // Usage unique : on consomme l'entrée pour interdire le rejeu.
        unset($this->pending[$ceremonyId]);

        return $options;
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
    }
}
