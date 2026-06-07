<?php

declare(strict_types=1);

namespace App\Dto;

use Waffle\Commons\Contracts\Attribute\Dto;
use Waffle\Commons\Utils\Assert;

/**
 * DTO d'inscription — vitrine du système d'assertions `Assert`
 * (`waffle-commons/utils`).
 *
 * Là où {@see HelloInput} porte une validation *inline* dans un hook `set`
 * complet, chaque propriété utilise ici un **hook court** PHP 8.5
 * (`set => expression`) qui délègue à une méthode d'`Assert`. Chaque assertion
 * *valide ET renvoie la valeur nettoyée* : validation et normalisation tiennent
 * donc en une seule ligne par champ.
 *
 *   - `email`    : adresse valide, puis trimée + mise en minuscules ;
 *   - `username` : non vide, puis longueur 3–32 (comptée en UTF-8) ;
 *   - `age`      : borné à l'intervalle inclusif 18–130 ;
 *   - `signupIp` : adresse IP v4/v6 valide, trimée + mise en minuscules.
 *
 * Une valeur invalide lève une `Waffle\Commons\Utils\Exception\ValidationException`
 * (qui implémente `ValidationExceptionInterface`) que le JsonErrorRenderer
 * sérialise en RFC 7807 « 422 Unprocessable Entity ». Les assertions étant de
 * niveau *valeur*, la clé `field` du corps d'erreur n'est pas renseignée ;
 * voyez {@see HelloInput} pour un message rattaché à un champ précis.
 *
 * La classe n'est volontairement pas `readonly` (un hook `set` l'interdit) :
 * l'immuabilité externe est exprimée par la visibilité asymétrique
 * `public private(set)`.
 */
#[Dto]
final class RegistrationInput
{
    /** Courriel validé, trimé et mis en minuscules. */
    public private(set) string $email {
        set => Assert::email($value);
    }

    /** Identifiant de 3 à 32 caractères (UTF-8), validé non vide. */
    public private(set) string $username {
        set => Assert::length(Assert::notEmpty($value), 3, 32);
    }

    /** Âge de l'inscrit, borné à l'intervalle inclusif 18–130. */
    public private(set) int $age {
        set => Assert::range($value, 18, 130);
    }

    /** Adresse IP (v4/v6) de l'inscription, trimée et mise en minuscules. */
    public private(set) string $signupIp {
        set => Assert::ip($value);
    }

    public function __construct(string $email, string $username, int $age, string $signupIp)
    {
        $this->email = $email;
        $this->username = $username;
        $this->age = $age;
        $this->signupIp = $signupIp;
    }
}
