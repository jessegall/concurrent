<?php

namespace JesseGall\Concurrent\Testing;

use JesseGall\Concurrent\Contracts\CacheDriver;

class InMemoryCache implements CacheDriver
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? (is_callable($default) ? $default() : $default);
    }

    public function put(string $key, mixed $value, int|null $ttl): void
    {
        $this->store[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }
}
