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
    private function set(string $key, mixed $value): static
    {
        $this->{$key} = $value;

        return $this;
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
     */
    private function update(Closure $fn): static
    {
        $this($fn);

        return $this;
    }

    /**
     * Forget the wrapped value — next read returns the default.
     */
    private function clear(): static
    {
        $this(null);

        return $this;
    }
}
