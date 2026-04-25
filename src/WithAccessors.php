<?php

namespace JesseGall\Concurrent;

use Closure;

/**
 * Adds private get/set/has/update helpers for reading and writing on the
 * wrapped value from within a Concurrent subclass.
 *
 * Private by default — these are intended as internal building blocks for
 * domain methods on the subclass. To expose them as public API, override
 * visibility via PHP's trait conflict resolution:
 *
 *     use WithAccessors {
 *         get as public;
 *         set as public;
 *         has as public;
 *         update as public;
 *     }
 *
 * Routing: $this->{$key} goes through Concurrent's __get/__set/__isset, so
 * reads and writes hit the wrapped value (with locking on writes). If the
 * subclass declares a real property with the same name, that property is
 * accessed directly — real properties take precedence over wrapped lookup.
 */
trait WithAccessors
{
    /**
     * Read a key from the wrapped value (or a real property on the subclass).
     * Returns $default if the key is missing or null.
     */
    private function get(string $key, mixed $default = null): mixed
    {
        return $this->{$key} ?? $default;
    }

    /**
     * Write a key on the wrapped value (or a real property on the subclass).
     * Goes through Concurrent's __set, which acquires the lock and writes back.
     */
    private function set(string $key, mixed $value): void
    {
        $this->{$key} = $value;
    }

    /**
     * Check whether a key exists (and is non-null) on the wrapped value or subclass.
     */
    private function has(string $key): bool
    {
        return isset($this->{$key});
    }

    /**
     * Run a closure as an atomic update of the wrapped value.
     *
     * Delegates to __invoke, so all callback styles work:
     *   - Zero-param closure: $this is bound to the Concurrent; mutate via
     *     proxy methods or return a value to store.
     *   - Param closure: receives the wrapped value, returns the new state.
     *   - By-reference param: mutates the value in place.
     */
    private function update(Closure $fn): void
    {
        $this($fn);
    }
}
