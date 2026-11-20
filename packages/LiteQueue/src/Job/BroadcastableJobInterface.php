<?php

declare(strict_types=1);

namespace LiteQueue\Job;

/**
 * Contract for background jobs that broadcast real-time status updates upon completion.
 */
interface BroadcastableJobInterface extends JobInterface
{
    /**
     * Target room / channel to broadcast to (e.g. 'user_101', 'room_orders', 'admin_alerts').
     */
    public function broadcastChannel(): string;

    /**
     * Event name for client listeners (e.g. 'export.ready', 'order.processed').
     */
    public function broadcastAs(): string;

    /**
     * Payload to send to client upon completion.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array;
}
