<?php

declare(strict_types=1);

namespace AppTests;

use App\Factory\AppKernelFactory;
use App\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Http\Factory\ServerRequestFactory;

/**
 * Test de fumée de boot des axes Beta5 câblés (AXE1–AXE6).
 *
 * Amorce le kernel en mode `dev` (scan de conformité stricte DIAG-02 ACTIF), ce
 * qui prouve que TOUS les services nouvellement enregistrés (buffer de diffusion,
 * runner de tâches différées, dépôt/magasin WebAuthn) sont resettables et
 * acceptés au lock du conteneur. Puis :
 *   - reset() vide les services à portée requête sans exception,
 *   - une requête GET synthétique sur une route de démo répond hors 5xx.
 */
final class BootSmokeTest extends AbstractTestCase
{
    private function bootKernel(): Kernel
    {
        // Le scan strict est piloté par l'argument env de la factory ; on force
        // aussi APP_ENV pour que AbstractKernel::boot() lise un environnement non
        // vide (sinon il retombe sur « prod »).
        putenv(Constant::APP_ENV . '=' . Constant::ENV_DEV);

        $kernel = AppKernelFactory::create(env: Constant::ENV_DEV, debug: true);
        self::assertInstanceOf(Kernel::class, $kernel);
        // boot()->configure() verrouille le conteneur : le scan strict refuserait
        // un service mutable non-resettable. Aucune exception ⇒ tout est conforme.
        $kernel->boot()->configure();

        return $kernel;
    }

    #[Test]
    public function kernel_boots_and_locks_with_every_beta5_service_resettable(): void
    {
        $kernel = $this->bootKernel();

        // reset() délègue à Container::reset() qui vide chaque ResettableInterface
        // (buffer, runner, WebAuthn) — doit passer sans lever.
        $kernel->reset();

        $this->addToAssertionCount(1);
        self::assertInstanceOf(KernelInterface::class, $kernel);
    }

    #[Test]
    public function reactive_and_async_services_are_registered_under_their_contracts(): void
    {
        $kernel = $this->bootKernel();
        $container = $kernel->container;

        self::assertTrue($container->has(BroadcastBufferInterface::class));
        self::assertTrue($container->has(TaskRunnerInterface::class));
    }

    #[Test]
    public function handle_serves_a_demo_route_without_a_server_error(): void
    {
        $kernel = $this->bootKernel();

        $request = new ServerRequestFactory()->createServerRequest(
            method: 'GET',
            uri: 'http://localhost/reactive/status',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $response = $kernel->handle($request);

        // Hors 5xx : la route de démo (publique, sans I/O) doit aboutir.
        self::assertLessThan(500, $response->getStatusCode());
        // Vidange finish-request des buffers, comme en boucle worker.
        $kernel->terminate($request, $response);
        $kernel->reset();
    }
}
