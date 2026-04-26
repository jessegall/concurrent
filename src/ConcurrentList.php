<?php

namespace JesseGall\Concurrent;

/**
 * Thread-safe ordered list. Mutating methods return a chain proxy that
 * batches a fluent chain into one lock automatically — `$list->add(1)
 * ->add(2)` is atomic; no explicit chain() call needed.
 */
class ConcurrentList extends Concurrent
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

    public function add(mixed $value): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
            fn () => $this(fn (array &$list) => $list[] = $value),
        );
    }

    public function get(int $index, mixed $default = null): mixed
    {
        return $this()[$index] ?? $default;
    }

    /** Removes by index and re-indexes the list. */
    public function remove(int $index): HigherOrderConcurrentChainProxy
    {
        return new HigherOrderConcurrentChainProxy($this)->queue(
            function () use ($index) {
                $this(function (array &$list) use ($index) {
                    array_splice($list, $index, 1);
                });
            },
        );
    }

    /** @return list<mixed> */
    public function all(): array
    {
        return $this();
    }

    public function count(): int
    {
        return count($this());
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Iterate while holding the lock. Return false from the callback to break early.
     *
     * @param  callable(mixed $value, int $index): mixed  $callback
     */
    public function each(callable $callback): HigherOrderConcurrentChainProxy
    {
        return new HigherOrderConcurrentChainProxy($this)->queue(
            function () use ($callback) {
                $this(function (array &$list) use ($callback) {
                    foreach ($list as $index => $value)
                    {
                        if ($callback($value, $index) === false)
                        {
                            break;
                        }
                    }
                });
            },
        );
    }

    /**
     * Transform each item. With & the callback mutates in place; without &
     * its return value replaces the item.
     *
     * @param  callable(mixed $value, int $index): mixed  $callback
     */
    public function map(callable $callback): HigherOrderConcurrentChainProxy
    {
        return new HigherOrderConcurrentChainProxy($this)->queue(
            function () use ($callback) {
                $byReference = CallableInspector::acceptsByReference($callback);

                $this(function (array &$list) use ($callback, $byReference) {
                    foreach ($list as $index => &$value)
                    {
                        if ($byReference)
                        {
                            $callback($value, $index);
                        }
                        else
                        {
                            $value = $callback($value, $index);
                        }
                    }
                });
            },
        );
    }

    /**
     * Keep items where the predicate returns true. Re-indexes.
     *
     * @param  callable(mixed $value, int $index): bool  $callback
     */
    public function filter(callable $callback): HigherOrderConcurrentChainProxy
    {
        return new HigherOrderConcurrentChainProxy($this)->queue(
            fn () => $this(fn (array $list) => array_values(array_filter($list, $callback, ARRAY_FILTER_USE_BOTH))),
        );
    }

    public function clear(): HigherOrderConcurrentChainProxy
    {
        return new HigherOrderConcurrentChainProxy($this)->queue(
            fn () => $this(fn () => []),
        );
    }
}
