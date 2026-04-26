<?php

namespace JesseGall\Concurrent\Laravel;

use Illuminate\Support\Facades\Cache;
use JesseGall\Concurrent\Contracts\LockDriver;

class LaravelLock implements LockDriver
{
    public function acquire(string $key, int $ttl, int $timeout, callable $callback): mixed
    {
        $lock = Cache::lock($key, $ttl);

        try {
            return $lock->block($timeout, $callback);
        } finally {
            $lock->release();
        }
    }
}
