<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\ConcurrentHashMap;

class ConcurrentHashMapTest extends TestCase
{
    public function test_set_and_get(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-set');

        $map->set('name', 'Jesse');

        $this->assertSame('Jesse', $map->get('name'));
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-default');

        $this->assertNull($map->get('missing'));
        $this->assertSame('fallback', $map->get('missing', 'fallback'));
    }

    public function test_has(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-has');

        $map->set('exists', true);

        $this->assertTrue($map->has('exists'));
        $this->assertFalse($map->has('nope'));
    }

    public function test_remove(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-remove');

        $map->set('key', 'value');
        $this->assertTrue($map->has('key'));

        $map->remove('key');
        $this->assertFalse($map->has('key'));
    }

    public function test_all(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-all');

        $map->set('a', 1);
        $map->set('b', 2);
        $map->set('c', 3);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $map->all());
    }

    public function test_overwrite_existing_key(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-overwrite');

        $map->set('key', 'first');
        $map->set('key', 'second');

        $this->assertSame('second', $map->get('key'));
    }

    public function test_persists_across_instances(): void
    {
        $first = new ConcurrentHashMap('test:hashmap-persist');
        $first->set('shared', 'data');

        $second = new ConcurrentHashMap('test:hashmap-persist');
        $this->assertSame('data', $second->get('shared'));
    }

    public function test_different_keys_are_isolated(): void
    {
        $a = new ConcurrentHashMap('test:hashmap-a');
        $b = new ConcurrentHashMap('test:hashmap-b');

        $a->set('key', 'from-a');
        $b->set('key', 'from-b');

        $this->assertSame('from-a', $a->get('key'));
        $this->assertSame('from-b', $b->get('key'));
    }

    public function test_remove_nonexistent_key_does_not_error(): void
    {
        $map = new ConcurrentHashMap('test:hashmap-remove-noop');

        $map->remove('nonexistent');

        $this->assertSame([], $map->all());
    }
}
