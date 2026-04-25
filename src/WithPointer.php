<?php

namespace JesseGall\Concurrent;

/**
 * Adds a "current instance" pointer to a Concurrent subclass.
 *
 * Provides:
 *  - static::pointer()  — the underlying ConcurrentPointer
 *  - static::start($id) — mint a fresh instance and claim the pointer
 *  - static::current()  — resolve the pointer, or null if unset
 *  - static::release()  — clear the pointer
 *
 * Subclasses MUST implement:
 *  - fromPointerId()    — how to build an instance from the persisted ID
 *
 * Override points:
 *  - pointerKey()       — the cache key (defaults to FQCN + ":pointer")
 *
 * Concurrent subclasses can have any constructor shape, so the trait can't
 * guess how to build one from an ID — that's what fromPointerId is for.
 * Extra args passed to start()/current() are forwarded to fromPointerId
 * so callers can supply runtime context (e.g. an injected service) that
 * shouldn't be persisted in the pointer.
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
     * Mint a fresh instance, claim the pointer, and return it.
     *
     * Extra args are spread into the constructor after the ID — for target
     * classes whose constructor takes more than just the ID. Only the ID is
     * persisted; extras must be supplied again on current().
     *
     * @return T
     */
    public static function start(string|null $id = null, mixed ...$args): static
    {
        return static::pointer()->start($id, ...$args);
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
}
