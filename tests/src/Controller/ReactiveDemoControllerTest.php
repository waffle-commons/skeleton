<?php

declare(strict_types=1);

namespace AppTests\Controller;

use App\Controller\ReactiveDemoController;
use App\Dto\OrderStatus;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Reactive\MutationRecord;
use Waffle\Commons\Http\Factory\ResponseFactory;

/**
 * Vitrine du temps réel piloté par les mutations (AXE3 / REACTIVE-01) : la sonde
 * GET n'a aucun effet de bord, et le POST met UNE mutation en file sans I/O.
 */
final class ReactiveDemoControllerTest extends AbstractTestCase
{
    private function controller(): ReactiveDemoController
    {
        $controller = new ReactiveDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        return $controller;
    }

    private function buffer(): BroadcastBufferInterface
    {
        return new class implements BroadcastBufferInterface {
            /** @var list<MutationRecord> */
            public array $records = [];

            #[\Override]
            public function record(MutationRecord $record): void
            {
                $this->records[] = $record;
            }

            /**
             * @return list<MutationRecord>
             */
            #[\Override]
            public function drain(): array
            {
                $drained = $this->records;
                $this->records = [];

                return $drained;
            }

            #[\Override]
            public function reset(): void
            {
                $this->records = [];
            }
        };
    }

    #[Test]
    public function status_reports_the_wired_buffer_without_any_side_effect(): void
    {
        $buffer = $this->buffer();

        $response = $this->controller()->status($buffer);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{reactive: bool, buffer: string, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['reactive']);
        self::assertSame($buffer::class, $payload['buffer']);
        self::assertSame([], $buffer->drain());
    }

    #[Test]
    public function mutate_order_enqueues_the_transition_on_the_orders_channel(): void
    {
        $buffer = $this->buffer();

        $response = $this->controller()->mutateOrder($buffer);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{order_id: string, status: string, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ORD-2026-0042', $payload['order_id']);
        self::assertSame('shipped', $payload['status']);

        // La graine du constructeur ne diffuse pas : une seule mutation en file.
        $drained = $buffer->drain();
        self::assertCount(1, $drained);
        $record = $drained[0] ?? self::fail('aucune mutation mise en file');
        self::assertSame('orders', $record->channel);
        self::assertSame(OrderStatus::class, $record->entityClass);
        self::assertSame('shipped', $record->value);
    }
}
