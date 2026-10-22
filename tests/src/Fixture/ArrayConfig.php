<?php

declare(strict_types=1);

namespace AppTests\Fixture;

use Waffle\Commons\Contracts\Config\ConfigInterface;

/**
 * Configuration en mémoire pour les tests des fabriques : une clé pointée
 * (`waffle.security.rate_limit.enabled`) → sa valeur, sans fichier YAML.
 */
final readonly class ArrayConfig implements ConfigInterface
{
    /** @param array<string, int|string|bool|list<int|string>> $values */
    public function __construct(
        private array $values,
    ) {}

    #[\Override]
    public function getInt(string $key, ?int $default = null): ?int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    #[\Override]
    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    #[\Override]
    public function getArray(string $key, ?array $default = null): ?array
    {
        $value = $this->values[$key] ?? null;

        return is_array($value) ? $value : $default;
    }

    #[\Override]
    public function getBool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }
}
