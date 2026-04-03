<?php

namespace JesseGall\Concurrent;

/**
 * A thread-safe ordered list backed by cache.
 *
 * Allows duplicates and preserves insertion order.
 * The each() method holds the lock for the entire iteration,
 * preventing other processes from modifying the list mid-loop.
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
    public function add(mixed $value): void
    {
        $this(fn (array &$list) => $list[] = $value);
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
    public function remove(int $index): void
    {
        $this(function (array &$list) use ($index) {
            array_splice($list, $index, 1);
        });
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
    public function each(callable $callback): void
    {
        $this(function (array &$list) use ($callback) {
            foreach ($list as $index => $value)
            {
                if ($callback($value, $index) === false)
                {
                    break;
                }
            }
        });
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
    public function map(callable $callback): void
    {
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
    }

    /**
     * Remove items that don't match the predicate. Re-indexes the list.
     * The lock is held for the entire operation.
     *
     * @param  callable(mixed $value, int $index): bool  $callback
     */
    public function filter(callable $callback): void
    {
        $this(fn (array $list) => array_values(array_filter($list, $callback, ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * Remove all items from the list.
     */
    public function clear(): void
    {
        $this(fn () => []);
    }
}
