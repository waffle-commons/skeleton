<?php

declare(strict_types=1);

namespace AppTests\Dto;

use App\Dto\OrderStatus;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Reactive\MutationRecord;

/**
 * DTO `#[Broadcast]` (AXE3 / REACTIVE-01).
 *
 * L'invariant du write-hook : la graine du constructeur ne diffuse RIEN (le
 * buffer n'est branché qu'après), seules les transitions ultérieures mettent une
 * `MutationRecord` en file — et le hook n'effectue aucune I/O.
 */
final class OrderStatusTest extends AbstractTestCase
{
    /**
     * Buffer concret enregistrant les mutations, plutôt qu'un mock sans attente.
     */
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
    public function the_constructor_seed_is_never_broadcast(): void
    {
        $buffer = $this->buffer();

        $order = new OrderStatus(orderId: 'ORD-1', status: 'pending', buffer: $buffer);

        self::assertSame('ORD-1', $order->orderId);
        self::assertSame('pending', $order->status);
        self::assertSame([], $buffer->drain());
    }

    #[Test]
    public function a_transition_enqueues_one_mutation_on_the_orders_channel(): void
    {
        $buffer = $this->buffer();
        $order = new OrderStatus(orderId: 'ORD-2', status: 'pending', buffer: $buffer);

        $order->transitionTo('shipped');

        $drained = $buffer->drain();
        self::assertCount(1, $drained);
        $record = $drained[0] ?? self::fail('aucune mutation mise en file');
        self::assertSame('orders', $record->channel);
        self::assertSame(OrderStatus::class, $record->entityClass);
        self::assertSame('status', $record->property);
        self::assertSame('shipped', $record->value);
        self::assertSame('shipped', $order->status);
    }

    #[Test]
    public function without_a_buffer_a_transition_still_mutates_and_stays_silent(): void
    {
        $order = new OrderStatus(orderId: 'ORD-3', status: 'pending');

        $order->transitionTo('cancelled');

        self::assertSame('cancelled', $order->status);
    }
}
