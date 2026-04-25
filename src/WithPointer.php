<?php

namespace JesseGall\Concurrent;

/**
 * Adds a "current instance" pointer to a Concurrent subclass.
 *
 * Provides:
 *  - static::pointer()   — the underlying ConcurrentPointer
 *  - static::start(...)  — mint a fresh instance with an auto-generated ID and claim the pointer
 *  - static::current()   — resolve the pointer, or null if unset
 *  - static::release()   — clear the pointer
 *
 * Subclasses MUST implement:
 *  - fromPointerId()     — how to build an instance from the persisted ID
 *
 * Override points:
 *  - pointerKey()        — the cache key (defaults to FQCN + ":pointer")
 *  - generateId()        — the new-instance ID (defaults to bin2hex(random_bytes(16)))
 *
 * Concurrent subclasses can have any constructor shape, so the trait can't
 * guess how to build one from an ID — that's what fromPointerId is for.
 * Extra args passed to start()/current() are forwarded to fromPointerId
 * so callers can supply runtime context (e.g. an injected service) that
 * shouldn't be persisted in the pointer.
 *
 * Need to claim a specific ID (rare)? Drop down to pointer()->start($id, ...).
 *
 * @template T of Concurrent
 */
trait WithPointer
{
    /**
     * Build an instance from the persisted pointer ID plus any runtime extras.
     *
     * @return T
     */
    abstract protected static function fromPointerId(string $id, mixed ...$args): static;

    /**
     * @return ConcurrentPointer<T>
     */
    public static function pointer(): ConcurrentPointer
    {
        return new ConcurrentPointer(
            key: static::pointerKey(),
            factory: static fn (string $id, mixed ...$args): static => static::fromPointerId($id, ...$args),
        );
    }

    /**
     * Mint a fresh instance with an auto-generated ID, claim the pointer, return it.
     *
     * Extra args are forwarded to fromPointerId() — for runtime context (e.g. an
     * injected service) that shouldn't be persisted. Only the ID is stored.
     *
     * @return T
     */
    public static function start(mixed ...$args): static
    {
        return static::pointer()->start(static::generateId(), ...$args);
    }

    /**
     * Resolve the pointed-to instance, or null if unset.
     *
     * @return T|null
     */
    public static function current(mixed ...$args): static|null
    {
        return static::pointer()->resolve(...$args);
    }

    /**
     * Clear the pointer.
     */
    public static function release(): void
    {
        static::pointer()->release();
    }

    /**
     * The pointer's cache key. Override to use a stable, class-name-independent key.
     */
    protected static function pointerKey(): string
    {
        return static::class . ':pointer';
    }

    /**
     * Generate the ID for a freshly-started instance. Override to plug in
     * a different scheme (UUIDs, ULIDs, snowflakes, etc.).
     */
    protected static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
