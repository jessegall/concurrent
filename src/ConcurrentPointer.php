<?php

namespace JesseGall\Concurrent;

use Closure;

/**
 * A Concurrent that stores the ID of "the current" instance of another
 * Concurrent class. Lets you mint new instances (claiming the pointer) or
 * resolve the pointed-to instance.
 *
 * Inherits TTL, cache driver, lock driver, and validation from Concurrent —
 * the pointer itself is just a Concurrent<string|null>.
 *
 * @template T of Concurrent
 * @extends Concurrent<string|null>
 */
final class ConcurrentPointer extends Concurrent
{
    /**
     * @param string $key Pointer cache key (e.g. "ean_import:current").
     * @param Closure(string, mixed...): T $factory Builds a target from an ID plus optional extra args.
     * @param Closure(): string|null $generateId Optional ID generator. Defaults to bin2hex(random_bytes(16)).
     * @param int|null $ttl Pointer cache TTL. Null stores forever.
     */
    public function __construct(
        string                        $key,
        private readonly Closure      $factory,
        private readonly Closure|null $generateId = null,
        int|null                      $ttl = null,
    )
    {
        parent::__construct(
            key: $key,
            ttl: $ttl
        );
    }

    /**
     * Mint a new ID, claim the pointer, and return a fresh target instance.
     * Overwrites any existing pointer.
     *
     * Extra args are spread into the factory after the ID — useful for target
     * constructors that take more than just the ID (e.g. an injected service).
     * Only the ID is persisted; extras must be supplied again at resolve() time.
     *
     * @param string|null $id Explicit ID. When null, generated.
     * @param mixed ...$args Extra constructor args, spread after the ID.
     * @return T
     */
    public function start(string|null $id = null, mixed ...$args): Concurrent
    {
        $id ??= ($this->generateId ?? static fn(): string => bin2hex(random_bytes(16)))();

        $this($id);

        return ($this->factory)($id, ...$args);
    }

    /**
     * Resolve the pointer to a target instance, or null if unset.
     *
     * Extra args are spread into the factory after the persisted ID.
     *
     * @param mixed ...$args Extra constructor args, spread after the ID.
     * @return T|null
     */
    public function resolve(mixed ...$args): Concurrent|null
    {
        $id = $this();

        return $id !== null ? ($this->factory)($id, ...$args) : null;
    }

    /**
     * Read the currently-pointed ID, or null if unset.
     */
    public function id(): string|null
    {
        return $this();
    }

    /**
     * Clear the pointer.
     */
    public function release(): void
    {
        $this(null);
    }
}
