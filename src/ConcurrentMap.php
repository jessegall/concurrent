<?php

namespace JesseGall\Concurrent;

/**
 * A thread-safe hash map backed by cache.
 *
 * Multiple processes can safely read and write to the same map
 * without race conditions — like Java's ConcurrentMap or Go's sync.Map.
 */
class ConcurrentMap extends Concurrent
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
     * Get a value by key, or return the default if not found.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this[$key] ?? $default;
    }

    /**
     * Set a key-value pair.
     */
    public function set(string $key, mixed $value): void
    {
        $this(function (array $map) use ($key, $value) {
            $map[$key] = $value;

            return $map;
        });
    }

    /**
     * Remove a key from the map.
     */
    public function remove(string $key): void
    {
        $this(function (array $map) use ($key) {
            unset($map[$key]);

            return $map;
        });
    }

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool
    {
        return isset($this[$key]);
    }

    /**
     * Get the entire map.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this();
    }
}
