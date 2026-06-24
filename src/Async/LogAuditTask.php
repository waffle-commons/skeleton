<?php

declare(strict_types=1);

namespace App\Async;

use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Tâche post-réponse de démonstration (AXE2 / ASYNC-01).
 *
 * Représente un travail court différé hors du chemin de latence perçue par
 * l'utilisateur (écriture d'audit, webhook, envoi de mail). Le
 * {@see \Waffle\Commons\Async\DeferredTaskRunner} l'exécute dans un Fiber isolé
 * sur {@see \Waffle\Event\TerminateEvent}, après l'émission de la réponse.
 *
 * `readonly` et autonome : aucune I/O lourde, aucun état partagé entre requêtes.
 */
final readonly class LogAuditTask implements \Waffle\Commons\Contracts\Async\DeferredTaskInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $action,
    ) {}

    #[\Override]
    public function run(): void
    {
        $this->logger->info(sprintf('[audit] action différée exécutée après réponse : %s', $this->action));
    }

    #[\Override]
    public function name(): string
    {
        return 'demo.audit';
    }
}
