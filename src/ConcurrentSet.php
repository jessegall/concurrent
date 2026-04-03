<?php

namespace JesseGall\Concurrent;

/**
 * A thread-safe set backed by cache.
 *
 * Stores unique values — duplicates are ignored.
 * Useful for tracking active users, in-progress jobs, feature flags, etc.
 */
class ConcurrentSet extends Concurrent
{
    public function __construct(string|null $key = null, int $ttl = 3600)
    {
        parent::__construct(
            key: $key,
            default: fn () => [],
            ttl: $ttl,
            validator: fn ($v) => is_array($v),
        );
    }

    /**
     * Add a value to the set. Duplicates are ignored.
     */
    public function add(string $value): void
    {
        $this(fn (array &$set) => $set[$value] = true);
    }

    /**
     * Remove a value from the set.
     */
    public function remove(string $value): void
    {
        $this(function (array &$set) use ($value) {
            unset($set[$value]);
        });
    }

    /**
     * Check if a value exists in the set.
     */
    public function contains(string $value): bool
    {
        return isset($this[$value]);
    }

    /**
     * Get all values in the set.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this());
    }

    /**
     * Get the number of values in the set.
     */
    public function count(): int
    {
        return count($this());
    }

    /**
     * Remove all values from the set.
     */
    public function clear(): void
    {
        $this(fn () => []);
    }
}
