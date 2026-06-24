<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\OrderStatus;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine du temps réel piloté par les mutations (AXE3 / REACTIVE-01).
 *
 * `POST /reactive/order` mute la propriété `#[Broadcast]` d'un {@see OrderStatus}.
 * Le write-hook enregistre une `MutationRecord` dans le buffer à portée requête
 * SANS I/O ; le `BroadcastFlushListener` la draine et la pousse en SSE sur
 * `TerminateEvent`, après l'émission de la réponse. La réponse confirme le compte
 * de mutations mises en file pour rendre le mécanisme observable.
 *
 * `#[PublicAccess]` : démo sans autorisation (ABAC fail-closed exige soit un
 * `#[Voter]`, soit cet opt-out explicite).
 */
#[Route(path: '/', name: 'reactive_demo_')]
#[PublicAccess]
final class ReactiveDemoController extends BaseController
{
    /**
     * Sonde GET sans effet de bord : confirme que le buffer de diffusion est bien
     * câblé (injection depuis le conteneur), sans muter ni effectuer d'I/O. Sert
     * de cible au test de fumée de boot.
     *
     * @throws RenderingException
     */
    #[Route(path: 'reactive/status', methods: [Routing::METHOD_GET], name: 'status')]
    public function status(BroadcastBufferInterface $buffer): ResponseInterface
    {
        return $this->jsonResponse(data: [
            'reactive' => true,
            'buffer' => $buffer::class,
            'note' => 'Le buffer #[Broadcast] est injecté depuis le conteneur ; POST /reactive/order pour diffuser une mutation.',
        ]);
    }

    /**
     * @throws RenderingException
     */
    #[Route(path: 'reactive/order', methods: [Routing::METHOD_POST], name: 'order')]
    public function mutateOrder(BroadcastBufferInterface $buffer): ResponseInterface
    {
        // Le DTO reçoit le buffer : chaque transition de `status` (write-hook
        // interne `#[Broadcast]`) met une mutation en file, sans I/O.
        $order = new OrderStatus(orderId: 'ORD-2026-0042', status: 'pending', buffer: $buffer);
        $order->transitionTo('shipped');

        return $this->jsonResponse(data: [
            'order_id' => $order->orderId,
            'status' => $order->status,
            'note' => 'Mutation diffusée sur le canal "orders" via SSE après la réponse (TerminateEvent).',
        ]);
    }
}
