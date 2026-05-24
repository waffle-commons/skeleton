<?php

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Attribute\Dto;

/**
 * Input DTO for the greeting endpoint (POST /greet).
 *
 * Hydrated by the framework's ControllerArgumentResolver from the JSON request
 * body (RFC-011): the resolver decodes the parsed body, matches keys to this
 * constructor's parameters by name, and instantiates the object.
 *
 * Validation is performed natively by a PHP 8.5 **Property Hook** — there is
 * intentionally NO external validation package, because validation is domain
 * logic that belongs to the value itself. A rejected value throws, and the
 * resolver maps that throw to an RFC 7807 `422 Unprocessable Entity`.
 *
 * The class is not `readonly` on purpose: PHP forbids a `set` hook on a
 * `readonly` property, so external immutability is expressed with **asymmetric
 * visibility** (`public private(set)`) instead — callers can read `$name` but
 * never reassign it, while the validating `set` hook still runs on hydration.
 */
#[Dto]
final class HelloInput
{
    public function __construct(
        public private(set) string $name {
            set(string $value) {
                $clean = trim($value);

                if ($clean === '' || preg_match('/^\p{L}+$/u', $clean) !== 1) {
                    throw new InvalidArgumentException(
                        'Field "name" must be a non-empty, alphabetic string.',
                    );
                }

                $this->name = $clean;
            }
        },
    ) {}
}
