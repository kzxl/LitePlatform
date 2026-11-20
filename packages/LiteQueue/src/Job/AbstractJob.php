<?php

declare(strict_types=1);

namespace LiteQueue\Job;

/**
 * Base job class providing sensible defaults for retry, timeout, and backoff.
 */
abstract class AbstractJob implements JobInterface
{
    /**
     * Default maximum retry attempts.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * Default execution timeout (60 seconds).
     */
    public function timeout(): int
    {
        return 60;
    }

    /**
     * Default delay in seconds before next retry attempt (5 seconds).
     */
    public function backoff(): int
    {
        return 5;
    }

    /**
     * Default hook when job exceeds all attempts.
     */
    public function failed(\Throwable $exception): void
    {
        // Subclasses can override to notify admins, send Slack/Discord alert, etc.
    }
}
