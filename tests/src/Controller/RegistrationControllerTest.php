<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\RegistrationController;
use App\Dto\RegistrationInput;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine du système d'assertions `Assert` : l'action rend les valeurs APRÈS
 * nettoyage par les hooks du DTO, ce qui rend le trim et la mise en minuscules
 * observables depuis la réponse.
 */
final class RegistrationControllerTest extends AbstractTestCase
{
    #[Test]
    public function register_echoes_the_sanitized_dto(): void
    {
        $controller = new RegistrationController();
        $controller->setResponseFactory(new ResponseFactory());

        $response = $controller->register(new RegistrationInput(
            email: '  Ada@Waffle.DEV  ',
            username: '  ada  ',
            age: 36,
            signupIp: '203.0.113.7',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{email: string, username: string, age: int, signup_ip: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ada@waffle.dev', $payload['email']);
        self::assertSame('ada', $payload['username']);
        self::assertSame(36, $payload['age']);
        self::assertSame('203.0.113.7', $payload['signup_ip']);
    }
}
