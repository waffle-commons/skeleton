<?php

declare(strict_types=1);

namespace AppTests\Fixture\Listener;

/**
 * Ce fichier mentionne AsEventListener dans sa documentation mais n'implémente
 * pas EventListenerInterface : la découverte l'instancie puis le rejette, sans
 * l'enregistrer.
 */
final class NotAListener
{
    public function handle(): void {}
}
