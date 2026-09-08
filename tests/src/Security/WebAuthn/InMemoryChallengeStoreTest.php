<?php

declare(strict_types=1);

namespace AppTests\Security\WebAuthn;

use App\Security\WebAuthn\InMemoryChallengeStore;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Auth\WebAuthn\AssertionOptions;

/**
 * Magasin de défis WebAuthn (AXE6 / AUTH-01).
 *
 * Deux invariants portent la sécurité : l'usage est UNIQUE (le défi est consommé
 * à la lecture, ce qui interdit le rejeu), et `reset()` vide l'état à chaque
 * boucle worker (`ResettableInterface` déclaré DIRECTEMENT — exigence igor).
 */
final class InMemoryChallengeStoreTest extends AbstractTestCase
{
    #[Test]
    public function a_remembered_challenge_is_returned_once_and_then_consumed(): void
    {
        $store = new InMemoryChallengeStore();
        $options = new AssertionOptions('chal-1', '{"challenge":"chal-1"}');

        $store->remember('ceremony-1', $options);

        self::assertSame($options, $store->take('ceremony-1'));
        // Usage unique : la seconde lecture ne rejoue rien.
        self::assertNull($store->take('ceremony-1'));
    }

    #[Test]
    public function an_unknown_ceremony_yields_null(): void
    {
        self::assertNull(new InMemoryChallengeStore()->take('inconnue'));
    }

    #[Test]
    public function reset_clears_every_pending_ceremony(): void
    {
        $store = new InMemoryChallengeStore();
        $store->remember('ceremony-1', new AssertionOptions('chal-1', '{}'));
        $store->remember('ceremony-2', new AssertionOptions('chal-2', '{}'));

        $store->reset();

        self::assertNull($store->take('ceremony-1'));
        self::assertNull($store->take('ceremony-2'));
    }
}
