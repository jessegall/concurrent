<?php

namespace JesseGall\Concurrent;

/**
 * Thread-safe collection of unique values. Duplicates are ignored.
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

    public function add(string $value): void
    {
        $this(fn () => $this->{$value} = true);
    }

    public function remove(string $value): void
    {
        $this(function () use ($value) { unset($this->{$value}); });
    }

    public function contains(string $value): bool
    {
        return isset($this[$value]);
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this());
    }

    public function count(): int
    {
        return count($this());
    }

    public function clear(): void
    {
        $this(fn () => []);
    }
}
