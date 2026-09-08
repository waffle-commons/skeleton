<?php

declare(strict_types=1);

namespace AppTests\Fixture\Listener;

use Waffle\Commons\Contracts\EventDispatcher\EventListenerInterface;
use Waffle\Commons\EventDispatcher\Attribute\AsEventListener;

/**
 * Écouteur de test valide : porte le marqueur ET implémente l'interface, donc la
 * découverte doit l'enregistrer auprès du ListenerProvider.
 */
final class DemoListener implements EventListenerInterface
{
    #[AsEventListener(event: DemoEvent::class)]
    public function onDemo(DemoEvent $event): void
    {
        // Aucun effet de bord : seul l'enregistrement est vérifié.
    }
}
