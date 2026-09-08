<?php

declare(strict_types=1);

namespace AppTests\Async;

use App\Async\LogAuditTask;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Stringable;

/**
 * Tâche différée post-réponse (AXE2 / ASYNC-01) : le contrat se limite à son nom
 * stable et à l'écriture d'audit émise lors de l'exécution dans le Fiber.
 */
final class LogAuditTaskTest extends AbstractTestCase
{
    #[Test]
    public function the_task_is_named_for_the_deferred_audit_channel(): void
    {
        self::assertSame('demo.audit', new LogAuditTask(new NullLogger(), 'demo.order.shipped')->name());
    }

    #[Test]
    public function running_the_task_writes_the_audit_line(): void
    {
        // Espion concret plutôt qu'un mock : une doublure sans attente déclarée
        // ferait échouer PHPUnit 12.5 (`failOnWarning`).
        $logger = new class extends NullLogger {
            /** @var list<string> */
            public array $lines = [];

            /**
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function info(string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };

        new LogAuditTask($logger, 'demo.order.shipped')->run();

        self::assertSame(['[audit] action différée exécutée après réponse : demo.order.shipped'], $logger->lines);
    }
}
