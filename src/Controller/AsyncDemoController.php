<?php

declare(strict_types=1);

namespace App\Controller;

use App\Async\LogAuditTask;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Log\Channel\LogChannel;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine de la déferralisation finish-request (AXE2 / ASYNC-01).
 *
 * `POST /async/audit` diffère une {@see LogAuditTask} dans le
 * {@see TaskRunnerInterface}. Le `DeferredTaskFlushListener` la draine sur
 * `TerminateEvent` : la tâche s'exécute dans un Fiber isolé APRÈS l'émission de
 * la réponse, hors du chemin de latence perçue. La réponse confirme le nombre de
 * tâches en attente pour rendre la mise en file observable.
 *
 * `#[PublicAccess]` : démo sans autorisation (opt-out ABAC explicite).
 */
#[Route(path: '/', name: 'async_demo_')]
final class AsyncDemoController extends BaseController
{
    /**
     * @throws RenderingException
     */
    #[Route(path: 'async/audit', methods: [Routing::METHOD_POST], name: 'audit')]
    #[PublicAccess]
    public function deferAudit(TaskRunnerInterface $runner): ResponseInterface
    {
        $runner->defer(new LogAuditTask(
            logger: new StreamLogger(channel: LogChannel::APP),
            action: 'demo.order.shipped',
        ));

        return $this->jsonResponse(data: [
            'deferred' => true,
            'pending' => $runner->pending(),
            'note' => 'Tâche exécutée après la réponse (TerminateEvent), dans un Fiber isolé, sous budget borné.',
        ]);
    }
}
