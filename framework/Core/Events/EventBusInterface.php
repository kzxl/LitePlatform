<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Events;

/**
 * Universal In-Memory EventBus Contract complying with Universe Architecture v4.0.
 * Decouples cross-module communication without direct module-to-module dependencies.
 */
interface EventBusInterface
{
    /**
     * Subscribe a listener to an event class.
     *
     * @param string $eventClass
     * @param callable $listener
     */
    public function subscribe(string $eventClass, callable $listener): void;

    /**
     * Dispatch an event object to all registered subscribers.
     *
     * @param object $event
     */
    public function dispatch(object $event): void;
}
