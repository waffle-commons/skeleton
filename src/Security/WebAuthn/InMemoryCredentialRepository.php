<?php

declare(strict_types=1);

namespace App\Security\WebAuthn;

use Waffle\Commons\Auth\WebAuthn\RegisteredCredential;
use Waffle\Commons\Contracts\Auth\WebAuthn\CredentialRepositoryInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\RegisteredCredentialInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

use function array_values;

/**
 * Dépôt de passkeys en mémoire — vitrine WebAuthn (AXE6 / AUTH-01).
 *
 * Le contrat {@see CredentialRepositoryInterface} est la SEULE partie avec état
 * du périmètre WebAuthn : il appartient à l'application. Une vraie application le
 * branche sur une base de données ; ce dépôt de démonstration garde les
 * identifiants en mémoire processus, ce qui suffit à rendre le cycle
 * register → assert observable sans backend externe.
 *
 * Worker-safety : l'état (la table des identifiants) étant à portée processus, le
 * dépôt implémente {@see ResettableInterface} DIRECTEMENT pour que le scan de
 * conformité stricte (DIAG-02) accepte le service et que le kernel le vide à
 * chaque boucle worker (`wfl igor` 0 KO).
 */
final class InMemoryCredentialRepository implements CredentialRepositoryInterface, ResettableInterface
{
    /** @var array<string, RegisteredCredentialInterface> Indexé par identifiant de credential (base64url). */
    private array $byCredentialId = [];

    #[\Override]
    public function findByCredentialId(string $credentialId): ?RegisteredCredentialInterface
    {
        return $this->byCredentialId[$credentialId] ?? null;
    }

    /**
     * @return list<RegisteredCredentialInterface>
     */
    #[\Override]
    public function findByUserHandle(string $userHandle): array
    {
        $matches = [];
        foreach ($this->byCredentialId as $credential) {
            if ($credential->userHandle() !== $userHandle) {
                continue;
            }

            $matches[] = $credential;
        }

        return $matches;
    }

    #[\Override]
    public function save(RegisteredCredentialInterface $credential): void
    {
        $this->byCredentialId[$credential->credentialId()] = $credential;
    }

    #[\Override]
    public function updateSignCount(string $credentialId, int $signCount): void
    {
        $existing = $this->byCredentialId[$credentialId] ?? null;
        if ($existing === null) {
            // No-op pour un credential inconnu (contrat).
            return;
        }

        $this->byCredentialId[$credentialId] = new RegisteredCredential(
            credentialId: $existing->credentialId(),
            publicKey: $existing->publicKey(),
            userHandle: $existing->userHandle(),
            signCount: $signCount,
            transports: array_values($existing->transports()),
        );
    }

    #[\Override]
    public function reset(): void
    {
        $this->byCredentialId = [];
    }
}
