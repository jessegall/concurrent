<?php

namespace JesseGall\Concurrent;

/**
 * Thread-safe FIFO queue. Push from one process, pop from another.
 */
class ConcurrentQueue extends Concurrent
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

    public function push(mixed $value): void
    {
        $this(fn (array &$queue) => $queue[] = $value);
    }

    public function pop(): mixed
    {
        $popped = null;

        $this(function (array $queue) use (&$popped) {
            $popped = array_shift($queue);

            return $queue;
        });

        return $popped;
    }

    public function peek(): mixed
    {
        $queue = $this();

        return $queue[0] ?? null;
    }

    public function size(): int
    {
        return count($this());
    }

    public function isEmpty(): bool
    {
        return $this->size() === 0;
    }

    public function clear(): void
    {
        $this(fn () => []);
    }
}
