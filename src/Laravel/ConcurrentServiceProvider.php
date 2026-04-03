<?php

namespace JesseGall\Concurrent\Laravel;

use Illuminate\Support\ServiceProvider;
use JesseGall\Concurrent\Contracts\CacheDriver;
use JesseGall\Concurrent\Contracts\LockDriver;

class ConcurrentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CacheDriver::class, fn () => new LaravelCache);
        $this->app->bind(LockDriver::class, fn () => new LaravelLock);
    }
}
