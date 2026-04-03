<?php

namespace JesseGall\Concurrent\Testing;

use JesseGall\Concurrent\Contracts\LockDriver;

class InMemoryLock implements LockDriver
{
    public function acquire(string $key, int $ttl, int $timeout, callable $callback): mixed
    {
        return $callback();
    }
}
