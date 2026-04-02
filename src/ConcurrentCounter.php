<?php

namespace JesseGall\Concurrent;

/**
 * A thread-safe counter backed by cache.
 *
 * Atomic increment, decrement, and reset — safe across processes.
 * Useful for rate limiting, visitor counts, job progress, etc.
 */
class ConcurrentCounter extends Concurrent
{
    public function __construct(string $key, int $ttl = 3600)
    {
        parent::__construct(
            key: $key,
            default: 0,
            ttl: $ttl,
        );
    }

    /**
     * Increment the counter by the given amount.
     */
    public function increment(int $amount = 1): void
    {
        $this(fn (int $count) => $count + $amount);
    }

    /**
     * Decrement the counter by the given amount.
     */
    public function decrement(int $amount = 1): void
    {
        $this(fn (int $count) => $count - $amount);
    }

    /**
     * Get the current count.
     */
    public function count(): int
    {
        return $this();
    }

    /**
     * Reset the counter to zero.
     */
    public function reset(): void
    {
        $this(0);
    }
}
