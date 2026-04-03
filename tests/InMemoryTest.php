<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Testing\InMemoryCache;
use JesseGall\Concurrent\Testing\InMemoryLock;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Tests that Concurrent works without Laravel, using in-memory implementations.
 */
class InMemoryTest extends BaseTestCase
{
    private InMemoryCache $cache;

    private InMemoryLock $lock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new InMemoryCache;
        $this->lock = new InMemoryLock;
    }

    public function test_get_and_set(): void
    {
        $concurrent = new Concurrent(key: 'test', default: 0, cache: $this->cache, lock: $this->lock);

        $concurrent(42);

        $this->assertSame(42, $concurrent());
    }

    public function test_default_value(): void
    {
        $concurrent = new Concurrent(key: 'test', default: 'hello', cache: $this->cache, lock: $this->lock);

        $this->assertSame('hello', $concurrent());
    }

    public function test_callable_default(): void
    {
        $concurrent = new Concurrent(key: 'test', default: fn () => ['empty'], cache: $this->cache, lock: $this->lock);

        $this->assertSame(['empty'], $concurrent());
    }

    public function test_forget(): void
    {
        $concurrent = new Concurrent(key: 'test', default: 'initial', cache: $this->cache, lock: $this->lock);

        $concurrent('stored');
        $concurrent(null);

        $this->assertSame('initial', $concurrent());
    }

    public function test_reference_parameter(): void
    {
        $concurrent = new Concurrent(key: 'test', default: 0, cache: $this->cache, lock: $this->lock);

        $concurrent(10);
        $concurrent(function (&$value) {
            $value += 5;
        });

        $this->assertSame(15, $concurrent());
    }

    public function test_object_proxy(): void
    {
        $obj = new class {
            public int $count = 0;

            public function increment(): void
            {
                $this->count++;
            }
        };

        $concurrent = new Concurrent(key: 'test', default: fn () => clone $obj, cache: $this->cache, lock: $this->lock);

        $concurrent->increment();
        $concurrent->increment();

        $this->assertSame(2, $concurrent->count);
    }

    public function test_array_access(): void
    {
        $concurrent = new Concurrent(key: 'test', default: fn () => [], cache: $this->cache, lock: $this->lock);

        $concurrent['key'] = 'value';

        $this->assertTrue(isset($concurrent['key']));
        $this->assertSame('value', $concurrent['key']);
    }

    public function test_shared_cache_instance(): void
    {
        $a = new Concurrent(key: 'shared', default: 0, cache: $this->cache, lock: $this->lock);
        $b = new Concurrent(key: 'shared', default: 0, cache: $this->cache, lock: $this->lock);

        $a(42);

        $this->assertSame(42, $b());
    }
}
