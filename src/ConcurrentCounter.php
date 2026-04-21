<?php

namespace JesseGall\Concurrent;

use InvalidArgumentException;

/**
 * A thread-safe counter backed by cache.
 *
 * Atomic increment, decrement, and reset — safe across processes.
 * Useful for rate limiting, visitor counts, job progress, etc.
 *
 * Supports optional bounds:
 *  - `$min` / `$max`   — inclusive clamp applied on every write.
 *  - `$wrap`           — when both bounds are set, values outside the
 *                        range wrap modulo-style (odometer / dice /
 *                        circular index) instead of clamping.
 */
class ConcurrentCounter extends Concurrent
{
    public function __construct(
        string|null $key = null,
        int $ttl = 3600,
        public int|null $min = null,
        public int|null $max = null,
        public bool $wrap = false,
    ) {
        if ($wrap && $max === null) {
            throw new InvalidArgumentException('ConcurrentCounter wrap requires max.');
        }

        if ($wrap && $min === null) {
            $this->min = $min = 0;
        }

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException(
                sprintf('ConcurrentCounter min (%d) must not be greater than max (%d).', $min, $max)
            );
        }

        parent::__construct(
            key: $key,
            default: $min ?? 0,
            ttl: $ttl,
            validator: fn ($v) => is_numeric($v),
        );
    }

    /**
     * Increment the counter by the given amount.
     */
    public function increment(int $amount = 1): void
    {
        $this(fn (int $count) => $this->applyBounds($count + $amount));
    }

    /**
     * Decrement the counter by the given amount.
     */
    public function decrement(int $amount = 1): void
    {
        $this(fn (int $count) => $this->applyBounds($count - $amount));
    }

    /**
     * Get the current count.
     */
    public function count(): int
    {
        return (int) $this();
    }

    /**
     * Reset the counter to its starting value (the configured min, or zero).
     */
    public function reset(): void
    {
        $this($this->min ?? 0);
    }

    /**
     * Apply clamp or wrap semantics if bounds are configured.
     */
    private function applyBounds(int $value): int
    {
        if ($this->wrap && $this->min !== null && $this->max !== null) {
            $range = $this->max - $this->min + 1;
            $offset = $value - $this->min;

            return $this->min + (($offset % $range) + $range) % $range;
        }

        if ($this->min !== null && $value < $this->min) {
            return $this->min;
        }

        if ($this->max !== null && $value > $this->max) {
            return $this->max;
        }

        return $value;
    }
}
