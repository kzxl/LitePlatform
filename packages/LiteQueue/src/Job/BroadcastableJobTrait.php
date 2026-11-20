<?php

declare(strict_types=1);

namespace LiteQueue\Job;

/**
 * Trait providing standard implementation of BroadcastableJobInterface.
 */
trait BroadcastableJobTrait
{
    protected ?string $broadcastChannel = null;
    protected ?string $broadcastAs = null;
    protected array $broadcastData = [];

    public function broadcastChannel(): string
    {
        return $this->broadcastChannel ?? 'default';
    }

    public function broadcastAs(): string
    {
        return $this->broadcastAs ?? static::class;
    }

    public function broadcastWith(): array
    {
        return $this->broadcastData;
    }

    /**
     * Configure broadcast target channel, event name, and payload.
     *
     * @param string $channel
     * @param string|null $as
     * @param array<string, mixed> $data
     */
    public function setBroadcast(string $channel, ?string $as = null, array $data = []): self
    {
        $this->broadcastChannel = $channel;
        if ($as !== null) {
            $this->broadcastAs = $as;
        }
        $this->broadcastData = $data;
        return $this;
    }
}
