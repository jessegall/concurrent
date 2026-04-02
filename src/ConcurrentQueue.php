<?php

namespace JesseGall\Concurrent;

/**
 * A thread-safe queue backed by cache.
 *
 * FIFO push/pop operations — safe across processes.
 * Useful for lightweight event buffers, task lists, or message passing.
 */
class ConcurrentQueue extends Concurrent
{
    public function __construct(string|null $key = null, int $ttl = 3600)
    {
        parent::__construct(
            key: $key,
            default: fn () => [],
            ttl: $ttl,
        );
    }

    /**
     * Push a value onto the end of the queue.
     */
    public function push(mixed $value): void
    {
        $this(function (array $queue) use ($value) {
            $queue[] = $value;

            return $queue;
        });
    }

    /**
     * Remove and return the first value from the queue.
     */
    public function pop(): mixed
    {
        $popped = null;

        $this(function (array $queue) use (&$popped) {
            $popped = array_shift($queue);

            return $queue;
        });

        return $popped;
    }

    /**
     * Return the first value without removing it.
     */
    public function peek(): mixed
    {
        $queue = $this();

        return $queue[0] ?? null;
    }

    /**
     * Get the number of items in the queue.
     */
    public function size(): int
    {
        return count($this());
    }

    /**
     * Check if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return $this->size() === 0;
    }

    /**
     * Remove all items from the queue.
     */
    public function clear(): void
    {
        $this(fn () => []);
    }
}
