<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegistrationInput;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine du système d'assertions `Assert` (`waffle-commons/utils`).
 *
 * L'endpoint hydrate un {@see RegistrationInput} dont chaque propriété valide ET
 * nettoie sa valeur via un hook court (`set => Assert::…($value)`). La réponse
 * renvoie les valeurs *après* nettoyage, ce qui rend le trim et la mise en
 * minuscules observables ; une entrée invalide ne parvient jamais à l'action —
 * le hook lève une `ValidationException` que l'ErrorHandlerMiddleware sérialise
 * en RFC 7807 « 422 ».
 */
#[Route(path: '/', name: 'registration_')]
final class RegistrationController extends BaseController
{
    /**
     * Inscription de démonstration : POST /register avec un corps JSON
     * `{"email": "...", "username": "...", "age": 30, "signupIp": "..."}`.
     *
     * @throws RenderingException
     */
    // Inscription publique : aucune identité n'existe encore au moment de
    // l'appel, donc aucun #[Voter] ne peut décider — l'opt-out explicite est la
    // seule façon d'atteindre l'action sous l'ABAC fail-closed.
    #[Route(path: 'register', methods: ['POST'], name: 'register')]
    #[PublicAccess]
    public function register(RegistrationInput $input): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'email' => $input->email,
            'username' => $input->username,
            'age' => $input->age,
            'signup_ip' => $input->signupIp,
        ]);
    }
}
