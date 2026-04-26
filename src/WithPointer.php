<?php

namespace JesseGall\Concurrent;

/**
 * Adds a "current instance" pointer to a Concurrent subclass: start() mints
 * a fresh instance + claims the pointer, current() resolves it, release()
 * clears it. Subclasses implement fromPointerId() since constructor shapes
 * vary; pointerKey() and generateId() are overridable hooks.
 *
 * @template T of Concurrent
 */
trait WithPointer
{
    /**
     * @return T
     */
    abstract protected static function fromPointerId(string $id, mixed ...$args): static;

    /** @return ConcurrentPointer<T> */
    public static function pointer(): ConcurrentPointer
    {
        return new ConcurrentPointer(
            key: static::pointerKey(),
            factory: static fn (string $id, mixed ...$args): static => static::fromPointerId($id, ...$args),
        );
    }

    /** @return T */
    public static function start(mixed ...$args): static
    {
        return static::pointer()->start(static::generateId(), ...$args);
    }

    /** @return T|null */
    public static function current(mixed ...$args): static|null
    {
        return static::pointer()->resolve(...$args);
    }

    public static function release(): void
    {
        static::pointer()->release();
    }

    protected static function pointerKey(): string
    {
        return static::class . ':pointer';
    }

    protected static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
