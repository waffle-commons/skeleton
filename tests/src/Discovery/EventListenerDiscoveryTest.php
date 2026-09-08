<?php

declare(strict_types=1);

namespace AppTests\Discovery;

use App\Discovery\EventListenerDiscovery;
use AppTests\AbstractTestCase;
use AppTests\Fixture\Listener\DemoEvent;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\EventDispatcher\Provider\ListenerProvider;

/**
 * Découverte des écouteurs (extraite d'AppKernelFactory en Beta-4).
 *
 * Le scan doit être strictement sélectif : seuls les fichiers PHP portant le
 * marqueur ET dont la classe implémente EventListenerInterface sont enregistrés.
 * Tout le reste — extension étrangère, fichier sans marqueur, fichier sans
 * classe, classe qui n'est pas un écouteur — est ignoré sans erreur.
 */
final class EventListenerDiscoveryTest extends AbstractTestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixture/Listener';

    #[Test]
    public function an_absent_directory_is_a_silent_no_op(): void
    {
        $provider = new ListenerProvider();

        EventListenerDiscovery::discover($provider, self::FIXTURES . '/inexistant');

        self::assertSame([], [...$provider->getListenersForEvent(new DemoEvent())]);
    }

    #[Test]
    public function only_the_annotated_listener_of_the_directory_is_registered(): void
    {
        $provider = new ListenerProvider();

        // Le répertoire contient aussi : un événement sans marqueur, une classe
        // portant le marqueur sans implémenter l'interface, un fichier PHP sans
        // classe, et un fichier .txt — tous doivent être ignorés.
        EventListenerDiscovery::discover($provider, self::FIXTURES);

        $listeners = [...$provider->getListenersForEvent(new DemoEvent())];
        self::assertCount(1, $listeners);
    }

    #[Test]
    public function discovery_is_idempotent_per_provider_instance(): void
    {
        $provider = new ListenerProvider();

        EventListenerDiscovery::discover($provider, self::FIXTURES);
        $afterFirstPass = count([...$provider->getListenersForEvent(new DemoEvent())]);

        self::assertSame(1, $afterFirstPass);
    }
}
