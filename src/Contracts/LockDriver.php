<?php

namespace JesseGall\Concurrent\Contracts;

interface LockDriver
{
    /**
     * Acquire a lock and execute the callback.
     * Blocks up to $timeout seconds waiting for the lock.
     * The lock is released when the callback completes.
     *
     * @throws \RuntimeException If the lock cannot be acquired within the timeout.
     */
    public function acquire(string $key, int $ttl, int $timeout, callable $callback): mixed;
}
