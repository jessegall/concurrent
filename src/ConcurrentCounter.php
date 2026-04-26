<?php

namespace JesseGall\Concurrent;

use InvalidArgumentException;

/**
 * Thread-safe atomic counter. Optional bounds: `min`/`max` clamp on every
 * write; `wrap` makes out-of-range values roll over modulo-style.
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
            validator: fn (mixed $v): bool => $this->isValidValue($v),
        );
    }

    public function increment(int $amount = 1): void
    {
        $this(fn (int $count) => $this->applyBounds($count + $amount));
    }

    public function decrement(int $amount = 1): void
    {
        $this(fn (int $count) => $this->applyBounds($count - $amount));
    }

    public function count(): int
    {
        return (int) $this();
    }

    /** Reset to min when bounded, zero otherwise. */
    public function reset(): void
    {
        $this($this->min ?? 0);
    }

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

    /** Out-of-range or non-numeric values are rejected so reads self-heal back to min. */
    private function isValidValue(mixed $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        if ($this->min !== null && $value < $this->min) {
            return false;
        }

        if ($this->max !== null && $value > $this->max) {
            return false;
        }

        return true;
    }
}
