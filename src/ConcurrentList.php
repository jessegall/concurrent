<?php

namespace JesseGall\Concurrent;

/**
 * A thread-safe ordered list backed by cache.
 *
 * Mutating methods (add/remove/map/filter/each/clear) return a chain proxy
 * that batches a fluent chain into one lock automatically — `$list->add(1)
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

    /**
     * Append a value to the list.
     */
    public function add(mixed $value): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
            fn () => $this(fn (array &$list) => $list[] = $value),
        );
    }

    /**
     * Get a value by index.
     */
    public function get(int $index, mixed $default = null): mixed
    {
        return $this()[$index] ?? $default;
    }

    /**
     * Remove a value by index and re-index the list.
     */
    public function remove(int $index): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
            function () use ($index) {
                $this(function (array &$list) use ($index) {
                    array_splice($list, $index, 1);
                });
            },
        );
    }

    /**
     * Get all values.
     *
     * @return list<mixed>
     */
    public function all(): array
    {
        return $this();
    }

    /**
     * Get the number of items.
     */
    public function count(): int
    {
        return count($this());
    }

    /**
     * Check if the list is empty.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Iterate over all items while holding the lock.
     * Return false from the callback to break early.
     *
     * @param  callable(mixed $value, int $index): mixed  $callback
     */
    public function each(callable $callback): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
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
     * Transform all items while holding the lock.
     *
     * With & — modify in-place:
     *   $list->map(function (float &$price) { $price *= 1.1; });
     *
     * Without & — return value replaces the item:
     *   $list->map(fn (float $price) => $price * 1.1);
     *
     * @param  callable(mixed $value, int $index): mixed  $callback
     */
    public function map(callable $callback): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
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
     * Remove items that don't match the predicate. Re-indexes the list.
     *
     * @param  callable(mixed $value, int $index): bool  $callback
     */
    public function filter(callable $callback): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
            fn () => $this(fn (array $list) => array_values(array_filter($list, $callback, ARRAY_FILTER_USE_BOTH))),
        );
    }

    /**
     * Remove all items from the list.
     */
    public function clear(): HigherOrderConcurrentChainProxy
    {
        return (new HigherOrderConcurrentChainProxy($this))->queue(
            fn () => $this(fn () => []),
        );
    }
}
