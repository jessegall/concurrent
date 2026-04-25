<?php

namespace JesseGall\Concurrent;

use Closure;

/**
 * A Concurrent<string|null> that stores the ID of "the current" instance of
 * another Concurrent class.
 *
 * @template T of Concurrent
 * @extends Concurrent<string|null>
 */
final class ConcurrentPointer extends Concurrent
{
    /**
     * @param Closure(string, mixed...): T $factory
     * @param Closure(): string|null $generateId
     */
    public function __construct(
        string                        $key,
        private readonly Closure      $factory,
        private readonly Closure|null $generateId = null,
        int|null                      $ttl = null,
    )
    {
        parent::__construct(key: $key, ttl: $ttl);
    }

    /**
     * @return T
     */
    public function start(string|null $id = null, mixed ...$args): Concurrent
    {
        $id ??= ($this->generateId ?? static fn(): string => bin2hex(random_bytes(16)))();

        $this($id);

        return ($this->factory)($id, ...$args);
    }

    /**
     * @return T|null
     */
    public function resolve(mixed ...$args): Concurrent|null
    {
        $id = $this();

        return $id !== null ? ($this->factory)($id, ...$args) : null;
    }

    public function id(): string|null
    {
        return $this();
    }

    public function release(): void
    {
        $this(null);
    }
}
