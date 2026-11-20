<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Events;

/**
 * Ultra-fast In-Memory EventBus implementation.
 */
class InMemoryEventBus implements EventBusInterface
{
    /**
     * @var array<string, list<callable>>
     */
    private array $listeners = [];

    public function subscribe(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): void
    {
        $eventClass = get_class($event);
        if (!empty($this->listeners[$eventClass])) {
            foreach ($this->listeners[$eventClass] as $listener) {
                $listener($event);
            }
        }
    }
}
