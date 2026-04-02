<?php

namespace JesseGall\Concurrent\Concerns;

use Closure;

/**
 * This trait provides methods to handle concurrent values.
 *
 * @template TValue The type of the cached value
 */
trait ConcurrentApi
{
    /**
     * Get the cached value.
     *
     * @return TValue
     */
    public function get(): mixed
    {
        return $this();
    }

    /**
     * @param  (callable(TValue): void)  $callback
     */
    public function read(callable $callback): void
    {
        $this($callback, read: true);
    }

    /**
     * Set the cached value.
     * If the value is a callable, it will be executed with the current cached value as an argument,
     * and the result will be used as the new cached value.
     *
     * @param  TValue  $value
     */
    public function set(mixed $value): void
    {
        $this($value);
    }

    /**
     * Apply a callback to the cached value within a single atomic transaction.
     *
     * Useful for making multiple modifications to the cached value in one operation
     * without multiple cache hits. To modify the value directly,
     * accept it by reference in your callback (&$value).
     *
     * @param  Closure(TValue): void  $transaction
     */
    public function update(Closure $transaction): void
    {
        $this(fn ($value) => tap($value, $transaction));
    }

    /**
     * Retrieve and remove the cached value.
     *
     * @return TValue
     */
    public function pull(): mixed
    {
        $value = $this();
        $this->forget();

        return $value;
    }

    /**
     * Forget the cached value.
     *
     * This method will remove the value from the cache.
     */
    public function forget(): void
    {
        $this(null);
    }

    /**
     * Execute a callback while holding a lock on the cached value.
     *
     * This is useful for ensuring that operations on the cached value are atomic
     * and not interrupted by other processes.
     *
     * @param  callable(TValue): mixed  $callback
     * @return mixed The result of the callback execution
     */
    public function withLock(callable $callback): mixed
    {
        return $this($callback, read: true);
    }
}
