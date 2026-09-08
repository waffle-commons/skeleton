<?php

declare(strict_types=1);

namespace AppTests\Security\WebAuthn;

use App\Security\WebAuthn\InMemoryCredentialRepository;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Auth\WebAuthn\RegisteredCredential;

/**
 * Dépôt de passkeys en mémoire (AXE6 / AUTH-01) : la moitié AVEC état du
 * périmètre WebAuthn, fournie par l'application. Le cycle enregistrement →
 * recherche → mise à jour du compteur → reset est le contrat entier.
 */
final class InMemoryCredentialRepositoryTest extends AbstractTestCase
{
    private function credential(string $id, string $userHandle, int $signCount = 0): RegisteredCredential
    {
        return new RegisteredCredential(
            credentialId: $id,
            publicKey: 'cle-publique-' . $id,
            userHandle: $userHandle,
            signCount: $signCount,
            transports: ['internal'],
        );
    }

    #[Test]
    public function a_saved_credential_is_found_by_its_identifier(): void
    {
        $repository = new InMemoryCredentialRepository();
        $credential = $this->credential('cred-1', 'user-1');

        $repository->save($credential);

        self::assertSame($credential, $repository->findByCredentialId('cred-1'));
    }

    #[Test]
    public function an_unknown_identifier_yields_null(): void
    {
        self::assertNull(new InMemoryCredentialRepository()->findByCredentialId('inconnu'));
    }

    #[Test]
    public function credentials_are_filtered_by_user_handle(): void
    {
        $repository = new InMemoryCredentialRepository();
        $repository->save($this->credential('cred-1', 'user-1'));
        $repository->save($this->credential('cred-2', 'user-2'));
        $repository->save($this->credential('cred-3', 'user-1'));

        $matches = $repository->findByUserHandle('user-1');

        self::assertCount(2, $matches);
        self::assertSame(
            ['cred-1', 'cred-3'],
            array_map(static fn($credential): string => $credential->credentialId(), $matches),
        );
        self::assertSame([], $repository->findByUserHandle('user-inconnu'));
    }

    #[Test]
    public function updating_the_sign_count_replaces_the_stored_credential(): void
    {
        $repository = new InMemoryCredentialRepository();
        $repository->save($this->credential('cred-1', 'user-1', signCount: 3));

        $repository->updateSignCount('cred-1', 9);

        $stored = $repository->findByCredentialId('cred-1');
        self::assertNotNull($stored);
        self::assertSame(9, $stored->signCount());
        // Le reste de l'identifiant est préservé à l'identique.
        self::assertSame('cle-publique-cred-1', $stored->publicKey());
        self::assertSame('user-1', $stored->userHandle());
        self::assertSame(['internal'], $stored->transports());
    }

    #[Test]
    public function updating_an_unknown_credential_is_a_no_op(): void
    {
        $repository = new InMemoryCredentialRepository();

        $repository->updateSignCount('inconnu', 42);

        self::assertNull($repository->findByCredentialId('inconnu'));
    }

    #[Test]
    public function reset_empties_the_repository(): void
    {
        $repository = new InMemoryCredentialRepository();
        $repository->save($this->credential('cred-1', 'user-1'));

        $repository->reset();

        self::assertNull($repository->findByCredentialId('cred-1'));
        self::assertSame([], $repository->findByUserHandle('user-1'));
    }
}
