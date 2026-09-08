<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\AsyncDemoController;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Async\DeferredTaskInterface;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine de la déferralisation finish-request (AXE2 / ASYNC-01).
 *
 * L'action ne DOIT rien exécuter : elle met la tâche en file et rend le compte
 * en attente observable — le drainage appartient au `TerminateEvent`.
 */
final class AsyncDemoControllerTest extends AbstractTestCase
{
    private function controller(): AsyncDemoController
    {
        $controller = new AsyncDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    #[Test]
    public function defer_audit_enqueues_the_task_without_running_it(): void
    {
        // File d'attente concrète : on observe la mise en file sans exécuter la
        // tâche, ce que ferait un vrai runner sur TerminateEvent.
        $runner = new class implements TaskRunnerInterface {
            /** @var list<DeferredTaskInterface> */
            public array $deferred = [];

            #[\Override]
            public function defer(DeferredTaskInterface $task): void
            {
                $this->deferred[] = $task;
            }

            #[\Override]
            public function run(): void
            {
                $this->deferred = [];
            }

            #[\Override]
            public function pending(): int
            {
                return count($this->deferred);
            }

            #[\Override]
            public function reset(): void
            {
                $this->deferred = [];
            }
        };

        $response = $this->controller()->deferAudit($runner);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $runner->deferred);
        $task = $runner->deferred[0] ?? self::fail('aucune tâche mise en file');
        self::assertSame('demo.audit', $task->name());

        /** @var array{deferred: bool, pending: int, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['deferred']);
        self::assertSame(1, $payload['pending']);
    }
}
