<?php

namespace JesseGall\Concurrent;

/**
 * Thread-safe hash map. Like Java's ConcurrentMap or Go's sync.Map.
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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this(fn () => $this->{$key} = $value);
    }

    public function remove(string $key): void
    {
        $this(function () use ($key) { unset($this->{$key}); });
    }

    public function has(string $key): bool
    {
        return isset($this[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this();
    }
}
