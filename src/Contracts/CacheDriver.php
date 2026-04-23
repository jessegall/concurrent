<?php

namespace JesseGall\Concurrent\Contracts;

interface CacheDriver
{
    /**
     * Retrieve a value from the cache.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store a value in the cache. A null TTL stores forever — the
     * driver keeps the value until it's explicitly forgotten.
     */
    public function put(string $key, mixed $value, int|null $ttl): void;

    /**
     * Remove a value from the cache.
     */
    public function forget(string $key): void;
}
