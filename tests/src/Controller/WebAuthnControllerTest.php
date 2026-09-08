<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\WebAuthnController;
use App\Security\WebAuthn\InMemoryChallengeStore;
use App\Security\WebAuthn\InMemoryCredentialRepository;
use AppTests\AbstractTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Auth\WebAuthn\AssertionOptions;
use Waffle\Commons\Auth\WebAuthn\RegistrationOptions;
use Waffle\Commons\Auth\WebAuthn\WebAuthnCeremony;
use Waffle\Commons\Contracts\Auth\WebAuthn\AssertionOptionsInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\RegisteredCredentialInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\RegistrationOptionsInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\WebAuthnUserInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\WebAuthnVerifierInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine WebAuthn / passkeys (AXE6 / AUTH-01) — émission d'options seulement.
 *
 * L'invariant à protéger : la cérémonie d'assertion mémorise son défi contre un
 * identifiant opaque à USAGE UNIQUE, sans quoi le rejeu deviendrait possible.
 */
final class WebAuthnControllerTest extends AbstractTestCase
{
    private function controller(): WebAuthnController
    {
        $controller = new WebAuthnController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    /**
     * Vérificateur concret : rend des options déterministes, la vérification
     * réelle exigeant une réponse d'authentificateur (hors périmètre de la démo).
     */
    private function ceremony(): WebAuthnCeremony
    {
        $verifier = new class implements WebAuthnVerifierInterface {
            /**
             * @param list<RegisteredCredentialInterface> $existing
             */
            #[\Override]
            public function createRegistrationOptions(
                WebAuthnUserInterface $user,
                array $existing = [],
            ): RegistrationOptionsInterface {
                return new RegistrationOptions('chal-register', '{"challenge":"chal-register"}');
            }

            #[\Override]
            public function verifyRegistration(
                RegistrationOptionsInterface $options,
                string $clientResponseJson,
            ): RegisteredCredentialInterface {
                throw new LogicException('La démo n\'émet que des options.');
            }

            /**
             * @param list<RegisteredCredentialInterface> $allowed
             */
            #[\Override]
            public function createAssertionOptions(array $allowed = []): AssertionOptionsInterface
            {
                return new AssertionOptions('chal-assert', '{"challenge":"chal-assert"}');
            }

            #[\Override]
            public function verifyAssertion(
                AssertionOptionsInterface $options,
                string $clientResponseJson,
                RegisteredCredentialInterface $credential,
            ): int {
                throw new LogicException('La démo n\'émet que des options.');
            }
        };

        return new WebAuthnCeremony($verifier, new InMemoryCredentialRepository());
    }

    #[Test]
    public function register_start_issues_attestation_options_with_an_opaque_handle(): void
    {
        $response = $this->controller()->registerStart($this->ceremony());

        self::assertSame(200, $response->getStatusCode());

        /** @var array{user_handle: string, challenge: string, publicKey: array<string, mixed>, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('chal-register', $payload['challenge']);
        // Handle opaque : 16 octets aléatoires en hexadécimal, jamais une PII.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['user_handle']);
    }

    #[Test]
    public function assert_start_remembers_the_challenge_for_a_single_replay(): void
    {
        $challenges = new InMemoryChallengeStore();

        $response = $this->controller()->assertStart($this->ceremony(), $challenges);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{ceremony_id: string, challenge: string, publicKey: array<string, mixed>, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('chal-assert', $payload['challenge']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['ceremony_id']);

        $remembered = $challenges->take($payload['ceremony_id']);
        self::assertNotNull($remembered);
        self::assertSame('chal-assert', $remembered->challenge());
        // Usage unique : le second retrait ne rejoue rien.
        self::assertNull($challenges->take($payload['ceremony_id']));
    }

    #[Test]
    public function two_assertion_ceremonies_never_share_an_identifier(): void
    {
        $challenges = new InMemoryChallengeStore();
        $ceremony = $this->ceremony();

        /** @var array{ceremony_id: string} $first */
        $first = json_decode(
            (string) $this->controller()->assertStart($ceremony, $challenges)->getBody(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        /** @var array{ceremony_id: string} $second */
        $second = json_decode(
            (string) $this->controller()->assertStart($ceremony, $challenges)->getBody(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertNotSame($first['ceremony_id'], $second['ceremony_id']);
    }
}
