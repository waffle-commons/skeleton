<?php

declare(strict_types=1);

namespace App\Dto;

use Waffle\Commons\Contracts\Reactive\Attribute\Broadcast;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Reactive\MutationRecord;

/**
 * DTO mutable de démonstration du temps réel (AXE3 / REACTIVE-01).
 *
 * La propriété `status` porte `#[Broadcast]` : son write-hook PHP 8.5 `set`
 * enregistre une {@see MutationRecord} dans le buffer de diffusion à portée
 * requête — SANS aucune I/O dans le hook. Le {@see \Waffle\Event\Listener\BroadcastFlushListener}
 * draine le buffer et pousse les mutations sérialisées sur le transport SSE
 * APRÈS l'émission de la réponse.
 *
 * Une propriété hookée ne pouvant être `readonly` en PHP 8.5, le DTO est un
 * `final class` à champ `public private(set)` (mutable maîtrisé), jamais un
 * `final readonly`.
 */
final class OrderStatus
{
    /**
     * Statut courant de la commande. Chaque écriture est diffusée sur le canal
     * `orders` via le buffer injecté ; le hook n'effectue aucune I/O.
     */
    /**
     * Buffer de diffusion, branché APRÈS la graine initiale du statut pour que
     * seules les transitions (et non l'état de départ) soient diffusées. `null`
     * tant que la graine n'est pas posée ⇒ le write-hook ne diffuse rien au boot.
     */
    private ?BroadcastBufferInterface $buffer = null;

    #[Broadcast(channel: 'orders')]
    public private(set) string $status {
        set(string $value) {
            $this->status = $value;
            // Le buffer n'est branché qu'après la graine du constructeur : la
            // première écriture (état de départ) ne diffuse donc rien ; seules les
            // transitions ultérieures sont diffusées.
            $this->buffer?->record(new MutationRecord(
                channel: 'orders',
                entityClass: self::class,
                property: 'status',
                value: $value,
            ));
        }
    }

    public function __construct(
        public readonly string $orderId,
        string $status,
        ?BroadcastBufferInterface $buffer = null,
    ) {
        // Graine d'abord (buffer encore null ⇒ pas de diffusion), puis branchement.
        $this->status = $status;
        $this->buffer = $buffer;
    }

    /**
     * Fait évoluer le statut. La mutation passe par le write-hook `set`
     * `#[Broadcast]` (visibilité `private(set)` : seule la classe écrit), qui met
     * la `MutationRecord` en file. À appeler depuis le contrôleur de démo.
     */
    public function transitionTo(string $status): void
    {
        $this->status = $status;
    }
}
