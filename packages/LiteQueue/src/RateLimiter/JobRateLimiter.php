<?php

declare(strict_types=1);

namespace LiteQueue\RateLimiter;

/**
 * Token bucket / sliding window rate limiter for queued jobs.
 * Prevents third-party API rate limit violations (e.g. SMTP, SMS gateways).
 */
class JobRateLimiter
{
    /** @var array<string, list<float>> In-memory timestamps: key => [microtime, ...] */
    private static array $hits = [];

    /**
     * Check if too many attempts have been made for a key within decaySeconds.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        self::cleanOldHits($key, $decaySeconds);
        return count(self::$hits[$key] ?? []) >= $maxAttempts;
    }

    /**
     * Increment the hit count for a key.
     */
    public static function hit(string $key): void
    {
        self::$hits[$key][] = microtime(true);
    }

    /**
     * Attempt to execute a callback if within rate limit; otherwise execute fallback or throw.
     *
     * @template T
     * @param callable(): T $callback
     * @param ?callable(): T $onLimitExceeded
     * @return T|null
     */
    public static function attempt(
        string $key,
        int $maxAttempts,
        int $decaySeconds,
        callable $callback,
        ?callable $onLimitExceeded = null,
    ): mixed {
        if (self::tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            if ($onLimitExceeded !== null) {
                return $onLimitExceeded();
            }
            throw new \RuntimeException("Rate limit exceeded for key [{$key}].");
        }

        self::hit($key);
        return $callback();
    }

    /**
     * Reset rate limit state for a key (or all keys).
     */
    public static function clear(?string $key = null): void
    {
        if ($key === null) {
            self::$hits = [];
        } else {
            unset(self::$hits[$key]);
        }
    }

    private static function cleanOldHits(string $key, int $decaySeconds): void
    {
        if (!isset(self::$hits[$key])) {
            return;
        }

        $cutoff = microtime(true) - $decaySeconds;
        self::$hits[$key] = array_values(array_filter(
            self::$hits[$key],
            fn(float $time) => $time > $cutoff
        ));
    }
}
