<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\ConcurrentSet;

class ConcurrentSetTest extends TestCase
{
    public function test_add_and_contains(): void
    {
        $set = new ConcurrentSet('test:set-add');

        $set->add('alice');
        $set->add('bob');

        $this->assertTrue($set->contains('alice'));
        $this->assertTrue($set->contains('bob'));
        $this->assertFalse($set->contains('charlie'));
    }

    public function test_duplicates_are_ignored(): void
    {
        $set = new ConcurrentSet('test:set-dup');

        $set->add('alice');
        $set->add('alice');
        $set->add('alice');

        $this->assertSame(1, $set->count());
    }

    public function test_remove(): void
    {
        $set = new ConcurrentSet('test:set-remove');

        $set->add('alice');
        $set->add('bob');
        $set->remove('alice');

        $this->assertFalse($set->contains('alice'));
        $this->assertTrue($set->contains('bob'));
    }

    public function test_all(): void
    {
        $set = new ConcurrentSet('test:set-all');

        $set->add('a');
        $set->add('b');
        $set->add('c');

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $set->all());
    }

    public function test_count(): void
    {
        $set = new ConcurrentSet('test:set-count');

        $set->add('a');
        $set->add('b');

        $this->assertSame(2, $set->count());
    }

    public function test_clear(): void
    {
        $set = new ConcurrentSet('test:set-clear');

        $set->add('a');
        $set->add('b');
        $set->clear();

        $this->assertSame(0, $set->count());
        $this->assertSame([], $set->all());
    }

    public function test_remove_nonexistent_does_not_error(): void
    {
        $set = new ConcurrentSet('test:set-remove-noop');

        $set->remove('nonexistent');

        $this->assertSame(0, $set->count());
    }

    public function test_persists_across_instances(): void
    {
        $first = new ConcurrentSet('test:set-persist');
        $first->add('shared');

        $second = new ConcurrentSet('test:set-persist');
        $this->assertTrue($second->contains('shared'));
    }

    public function test_contains_on_empty_set(): void
    {
        $set = new ConcurrentSet('test:set-empty-contains');

        $this->assertFalse($set->contains('anything'));
    }

    public function test_all_when_empty(): void
    {
        $set = new ConcurrentSet('test:set-empty-all');

        $this->assertSame([], $set->all());
    }

    public function test_auto_key_as_class_property(): void
    {
        $owner = new TestSetOwner;
        $owner->tags->add('php');

        $another = new TestSetOwner;
        $this->assertTrue($another->tags->contains('php'));
    }
}

class TestSetOwner
{
    public ConcurrentSet $tags;

    public function __construct()
    {
        $this->tags = new ConcurrentSet;
    }
}
