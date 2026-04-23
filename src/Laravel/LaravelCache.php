<?php

namespace JesseGall\Concurrent\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use JesseGall\Concurrent\Contracts\CacheDriver;

class LaravelCache implements CacheDriver
{
    public function __construct(
        private readonly string|array|null $tags = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository()->get($key, $default);
    }

    public function put(string $key, mixed $value, int|null $ttl): void
    {
        // Laravel's Cache::put interprets a null TTL as "store forever".
        $this->repository()->put($key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $this->repository()->forget($key);
    }

    private function repository(): Repository
    {
        if ($this->tags)
        {
            return Cache::tags($this->tags);
        }

        return Cache::store();
    }
}
