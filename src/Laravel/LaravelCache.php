<?php

namespace JesseGall\Concurrent\Laravel;

use __PHP_Incomplete_Class;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use JesseGall\Concurrent\Contracts\CacheDriver;
use JesseGall\Concurrent\Exceptions\RestrictedCacheException;

class LaravelCache implements CacheDriver
{
    public function __construct(
        private readonly string|array|null $tags = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->repository()->get($key, $default);

        if ($value instanceof __PHP_Incomplete_Class) {
            throw RestrictedCacheException::forIncompleteClass($key, $this->incompleteClassName($value));
        }

        return $value;
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

    /**
     * Recover the original class name stashed inside an incomplete object.
     */
    private function incompleteClassName(__PHP_Incomplete_Class $value): string
    {
        return ((array) $value)['__PHP_Incomplete_Class_Name'] ?? 'unknown';
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
