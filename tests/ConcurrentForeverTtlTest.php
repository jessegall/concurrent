<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Contracts\CacheDriver;
use JesseGall\Concurrent\Testing\InMemoryLock;

/**
 * Covers the "no TTL = forever" construction path — omitting the `ttl`
 * argument on the Concurrent constructor must resolve to null on the
 * way out to the cache driver, which every driver is expected to treat
 * as "store until explicitly forgotten".
 */
class ConcurrentForeverTtlTest extends TestCase
{

    public function test_defaults_to_null_ttl_when_unspecified(): void
    {
        $cache = new RecordingCache;

        $concurrent = new Concurrent(
            key: 'forever:default',
            default: 0,
            cache: $cache,
            lock: new InMemoryLock,
        );

        $concurrent('written');

        $this->assertCount(1, $cache->puts);
        $this->assertNull(
            $cache->puts[0]['ttl'],
            'Constructing without a TTL must cache with ttl=null (forever)',
        );
    }

    public function test_explicit_ttl_is_still_forwarded(): void
    {
        $cache = new RecordingCache;

        $concurrent = new Concurrent(
            key: 'forever:explicit',
            default: 0,
            ttl: 60,
            cache: $cache,
            lock: new InMemoryLock,
        );

        $concurrent('written');

        $this->assertSame(60, $cache->puts[0]['ttl']);
    }

}

/**
 * Minimal CacheDriver that records each put() so the test can assert
 * on the TTL value that reached the driver.
 */
class RecordingCache implements CacheDriver
{

    /** @var list<array{key: string, value: mixed, ttl: int|null}> */
    public array $puts = [];

    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->store))
        {
            return $this->store[$key];
        }

        return is_callable($default) ? $default() : $default;
    }

    public function put(string $key, mixed $value, int|null $ttl): void
    {
        $this->store[$key] = $value;
        $this->puts[] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

}
