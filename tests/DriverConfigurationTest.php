<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\ConcurrentCounter;
use JesseGall\Concurrent\Testing\InMemoryCache;
use JesseGall\Concurrent\Testing\InMemoryLock;
use PHPUnit\Framework\TestCase as BaseTestCase;

class DriverConfigurationTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Concurrent::useCache(new InMemoryCache);
        Concurrent::useLock(new InMemoryLock);
    }

    protected function tearDown(): void
    {
        Concurrent::resetDrivers();

        parent::tearDown();
    }

    public function test_use_cache_applies_to_all_new_instances(): void
    {
        $cache = new InMemoryCache;

        Concurrent::resetDrivers();
        Concurrent::useCache($cache);
        Concurrent::useLock(new InMemoryLock);

        $a = new Concurrent(key: 'a', default: 0);
        $b = new Concurrent(key: 'b', default: 0);

        $a(42);
        $b(99);

        // Both instances share the same cache
        $this->assertSame(42, $cache->get('a'));
        $this->assertSame(99, $cache->get('b'));
    }

    public function test_reset_drivers_clears_defaults(): void
    {
        Concurrent::resetDrivers();

        // Without default drivers set, constructing falls through to
        // Laravel container resolution — which fails since no service provider is registered
        $this->expectException(\Throwable::class);

        new Concurrent(key: 'test', default: 0);
    }

    public function test_constructor_drivers_override_global(): void
    {
        $globalCache = new InMemoryCache;
        $localCache = new InMemoryCache;

        Concurrent::resetDrivers();
        Concurrent::useCache($globalCache);
        Concurrent::useLock(new InMemoryLock);

        $concurrent = new Concurrent(key: 'test', default: 0, cache: $localCache, lock: new InMemoryLock);
        $concurrent(42);

        // Value is in the local cache, not the global one
        $this->assertSame(42, $localCache->get('test'));
        $this->assertNull($globalCache->get('test'));
    }

    public function test_subclasses_inherit_global_drivers(): void
    {
        $counter = new ConcurrentCounter('test:counter');

        $counter->increment();
        $counter->increment(5);

        $this->assertSame(6, $counter->count());
    }
}
