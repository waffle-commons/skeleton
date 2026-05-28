<?php

declare(strict_types=1);

namespace App\Dto;

use Waffle\Commons\Contracts\Attribute\Dto;
use Waffle\Exception\ValidationException;

/**
 * DTO d'entrée pour l'endpoint de salutation (POST /greet).
 *
 * Hydraté par le ControllerArgumentResolver du framework à partir du corps JSON
 * (RFC-011) : le resolver décode le body parsé, associe les clés aux paramètres
 * du constructeur par nom, puis instancie l'objet.
 *
 * La validation est portée nativement par un PHP 8.5 **Property Hook** — il n'y
 * a *aucune* dépendance à une bibliothèque de validation tierce, parce que la
 * validation est une logique de domaine qui appartient à la valeur elle-même.
 * Une valeur rejetée lève une exception que le JsonErrorRenderer transforme en
 * réponse RFC 7807 « 422 Unprocessable Entity ».
 *
 * La classe n'est volontairement pas `readonly` : PHP interdit un hook `set` sur
 * une propriété `readonly`. L'immuabilité externe est donc exprimée par la
 * **visibilité asymétrique** (`public private(set)`) — les appelants peuvent
 * lire `$name` mais jamais le réassigner, tandis que le hook `set` valide la
 * valeur lors de l'hydratation.
 */
#[Dto]
final class HelloInput
{
    public function __construct(
        public private(set) string $name {
            set(string $value) {
                $clean = trim($value);

                if ($clean === '' || preg_match('/^\p{L}+$/u', $clean) !== 1) {
                    throw new ValidationException(
                        message: 'Le champ « name » doit être une chaîne non vide composée uniquement de lettres.',
                        field: 'name',
                    );
                }

                $this->name = $clean;
            }
        },
    ) {}
}
