<?php

declare(strict_types=1);

namespace Lite\Framework\Security;

use LiteCache\CacheInterface;

/**
 * Cache-backed brute-force protection for authentication endpoints.
 * Works on Shared Hosting via LiteCache FileOpCacheDriver or DatabaseDriver without Redis.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxAttempts = 5,
        private readonly int $decaySeconds = 900
    ) {
    }

    public function tooManyAttempts(string $key): bool
    {
        $state = $this->getState($key);
        if ($state === null) {
            return false;
        }

        return $state['attempts'] >= $this->maxAttempts && $state['reset_at'] > time();
    }

    public function availableIn(string $key): int
    {
        $state = $this->getState($key);
        if ($state === null) {
            return 0;
        }

        return max(0, $state['reset_at'] - time());
    }

    public function hit(string $key): int
    {
        $now = time();
        $state = $this->getState($key);

        if ($state === null || $state['reset_at'] <= $now) {
            $attempts = 1;
            $resetAt = $now + $this->decaySeconds;
        } else {
            $attempts = $state['attempts'] + 1;
            $resetAt = $state['reset_at'];
        }

        $ttl = max(1, $resetAt - $now);
        $this->cache->set($this->cacheKey($key), [
            'attempts' => $attempts,
            'reset_at' => $resetAt,
        ], $ttl);

        return $attempts;
    }

    public function clear(string $key): void
    {
        $this->cache->delete($this->cacheKey($key));
    }

    /**
     * @return array{attempts: int, reset_at: int}|null
     */
    private function getState(string $key): ?array
    {
        $raw = $this->cache->get($this->cacheKey($key));
        if (!is_array($raw) || !isset($raw['attempts'], $raw['reset_at'])) {
            return null;
        }

        return [
            'attempts' => (int)$raw['attempts'],
            'reset_at' => (int)$raw['reset_at'],
        ];
    }

    private function cacheKey(string $key): string
    {
        return 'login_throttle_' . hash('sha256', strtolower(trim($key)));
    }
}
