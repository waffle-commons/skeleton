<?php

declare(strict_types=1);

namespace AppTests\Fixture\Listener;

/**
 * Événement de test. Ce fichier ne contient PAS le marqueur d'attribut : la
 * découverte doit l'ignorer sans même tenter d'en extraire un FQCN.
 */
final class DemoEvent
{
    public function __construct(
        public readonly string $payload = 'demo',
    ) {}
}
