<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\ConcurrentList;
use JesseGall\Concurrent\Testing\InMemoryCache;
use JesseGall\Concurrent\Contracts\LockDriver;

class ConcurrentListTest extends TestCase
{
    public function test_add_and_get(): void
    {
        $list = new ConcurrentList('test:list-add');

        $list->add('first');
        $list->add('second');

        $this->assertSame('first', $list->get(0));
        $this->assertSame('second', $list->get(1));
    }

    public function test_allows_duplicates(): void
    {
        $list = new ConcurrentList('test:list-dup');

        $list->add('same');
        $list->add('same');
        $list->add('same');

        $this->assertSame(3, $list->count());
    }

    public function test_preserves_insertion_order(): void
    {
        $list = new ConcurrentList('test:list-order');

        $list->add('c');
        $list->add('a');
        $list->add('b');

        $this->assertSame(['c', 'a', 'b'], $list->all());
    }

    public function test_get_returns_default_for_missing_index(): void
    {
        $list = new ConcurrentList('test:list-default');

        $this->assertNull($list->get(0));
        $this->assertSame('fallback', $list->get(99, 'fallback'));
    }

    public function test_remove_by_index(): void
    {
        $list = new ConcurrentList('test:list-remove');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $list->remove(1);

        $this->assertSame(['a', 'c'], $list->all());
    }

    public function test_remove_reindexes(): void
    {
        $list = new ConcurrentList('test:list-reindex');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $list->remove(0);

        $this->assertSame('b', $list->get(0));
        $this->assertSame('c', $list->get(1));
    }

    public function test_count(): void
    {
        $list = new ConcurrentList('test:list-count');

        $this->assertSame(0, $list->count());

        $list->add('a');
        $list->add('b');

        $this->assertSame(2, $list->count());
    }

    public function test_is_empty(): void
    {
        $list = new ConcurrentList('test:list-empty');

        $this->assertTrue($list->isEmpty());

        $list->add('a');

        $this->assertFalse($list->isEmpty());
    }

    public function test_clear(): void
    {
        $list = new ConcurrentList('test:list-clear');

        $list->add('a');
        $list->add('b');
        $list->clear();

        $this->assertSame([], $list->all());
        $this->assertTrue($list->isEmpty());
    }

    // ----------[ each ]----------

    public function test_each_iterates_all_items(): void
    {
        $list = new ConcurrentList('test:list-each');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $collected = [];

        $list->each(function (string $value, int $index) use (&$collected) {
            $collected[] = "{$index}:{$value}";
        });

        $this->assertSame(['0:a', '1:b', '2:c'], $collected);
    }

    public function test_each_breaks_on_false(): void
    {
        $list = new ConcurrentList('test:list-each-break');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $collected = [];

        $list->each(function (string $value) use (&$collected) {
            $collected[] = $value;

            if ($value === 'b')
            {
                return false;
            }
        });

        $this->assertSame(['a', 'b'], $collected);
    }

    public function test_each_does_not_modify_values(): void
    {
        $list = new ConcurrentList('test:list-each-readonly');

        $list->add(10);
        $list->add(20);

        $list->each(fn (int $value) => $value * 100);

        $this->assertSame([10, 20], $list->all());
    }

    public function test_each_holds_lock_for_entire_iteration(): void
    {
        $lockLog = [];

        $lock = new class($lockLog) implements LockDriver {
            /** @var list<array{action: string, key: string}> */
            private array $log;

            public function __construct(array &$log)
            {
                $this->log = &$log;
            }

            public function acquire(string $key, int $ttl, int $timeout, callable $callback): mixed
            {
                $this->log[] = ['action' => 'acquire', 'key' => $key];
                $result = $callback();
                $this->log[] = ['action' => 'release', 'key' => $key];

                return $result;
            }
        };

        Concurrent::useCache(new InMemoryCache);
        Concurrent::useLock($lock);

        $list = new ConcurrentList(key: 'test:list-each-lock');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $lockLog = [];
        $visited = [];

        $list->each(function (string $value) use (&$visited) {
            $visited[] = $value;
        });

        $this->assertCount(2, $lockLog);
        $this->assertSame('acquire', $lockLog[0]['action']);
        $this->assertSame('release', $lockLog[1]['action']);
        $this->assertSame(['a', 'b', 'c'], $visited);
    }

    // ----------[ map ]----------

    public function test_map_with_reference_modifies_in_place(): void
    {
        $list = new ConcurrentList('test:list-map-ref');

        $list->add(10.0);
        $list->add(20.0);
        $list->add(30.0);

        $list->map(function (float &$price) {
            $price *= 1.1;
        });

        $this->assertSame([11.0, 22.0, 33.0], $list->all());
    }

    public function test_map_without_reference_replaces_via_return(): void
    {
        $list = new ConcurrentList('test:list-map-return');

        $list->add(10.0);
        $list->add(20.0);
        $list->add(30.0);

        $list->map(fn (float $price) => $price * 1.1);

        $this->assertSame([11.0, 22.0, 33.0], $list->all());
    }

    public function test_map_receives_index(): void
    {
        $list = new ConcurrentList('test:list-map-index');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $list->map(fn (string $value, int $index) => "{$index}:{$value}");

        $this->assertSame(['0:a', '1:b', '2:c'], $list->all());
    }

    // ----------[ filter ]----------

    public function test_filter_keeps_matching_items(): void
    {
        $list = new ConcurrentList('test:list-filter');

        $list->add(1);
        $list->add(2);
        $list->add(3);
        $list->add(4);
        $list->add(5);

        $list->filter(fn (int $value) => $value > 2);

        $this->assertSame([3, 4, 5], $list->all());
    }

    public function test_filter_receives_index(): void
    {
        $list = new ConcurrentList('test:list-filter-index');

        $list->add('a');
        $list->add('b');
        $list->add('c');

        $list->filter(fn (string $value, int $index) => $index !== 1);

        $this->assertSame(['a', 'c'], $list->all());
    }

    public function test_filter_reindexes(): void
    {
        $list = new ConcurrentList('test:list-filter-reindex');

        $list->add('keep');
        $list->add('remove');
        $list->add('keep');

        $list->filter(fn (string $value) => $value === 'keep');

        $this->assertSame('keep', $list->get(0));
        $this->assertSame('keep', $list->get(1));
        $this->assertNull($list->get(2));
    }

    public function test_filter_removes_all_when_none_match(): void
    {
        $list = new ConcurrentList('test:list-filter-none');

        $list->add('a');
        $list->add('b');

        $list->filter(fn () => false);

        $this->assertSame([], $list->all());
    }

    // ----------[ chain ]----------

    public function test_chain_executes_all_operations_in_single_lock(): void
    {
        $lockLog = [];

        $lock = new class($lockLog) implements LockDriver {
            private array $log;

            public function __construct(array &$log)
            {
                $this->log = &$log;
            }

            public function acquire(string $key, int $ttl, int $timeout, callable $callback): mixed
            {
                $this->log[] = 'acquire';
                $result = $callback();
                $this->log[] = 'release';

                return $result;
            }
        };

        Concurrent::useCache(new InMemoryCache);
        Concurrent::useLock($lock);

        $list = new ConcurrentList(key: 'test:list-chain-lock');

        $list->add(1);
        $list->add(2);
        $list->add(3);

        $lockLog = [];

        $list->chain()
            ->map(fn (int $v) => $v * 10)
            ->filter(fn (int $v) => $v > 10)
            ->flush();

        // One acquire + release for the entire chain
        $this->assertSame(['acquire', 'release'], $lockLog);
        $this->assertSame([20, 30], $list->all());
    }

    public function test_chain_map_then_filter(): void
    {
        $list = new ConcurrentList('test:list-chain-map-filter');

        $list->add(1);
        $list->add(2);
        $list->add(3);
        $list->add(4);
        $list->add(5);

        $list->chain()
            ->map(fn (int $v) => $v * 2)
            ->filter(fn (int $v) => $v > 4)
            ->flush();

        $this->assertSame([6, 8, 10], $list->all());
    }

    public function test_chain_filter_then_map(): void
    {
        $list = new ConcurrentList('test:list-chain-filter-map');

        $list->add(1);
        $list->add(2);
        $list->add(3);

        $list->chain()
            ->filter(fn (int $v) => $v > 1)
            ->map(fn (int $v) => $v * 100)
            ->flush();

        $this->assertSame([200, 300], $list->all());
    }

    public function test_chain_with_each(): void
    {
        $list = new ConcurrentList('test:list-chain-each');

        $list->add(10);
        $list->add(20);
        $list->add(30);

        $collected = [];

        $list->chain()
            ->map(fn (int $v) => $v + 1)
            ->each(function (int $v) use (&$collected) {
                $collected[] = $v;
            })
            ->flush();

        $this->assertSame([11, 21, 31], $collected);
        $this->assertSame([11, 21, 31], $list->all());
    }

    public function test_chain_each_break_does_not_affect_other_operations(): void
    {
        $list = new ConcurrentList('test:list-chain-each-break');

        $list->add(1);
        $list->add(2);
        $list->add(3);

        $collected = [];

        $list->chain()
            ->map(fn (int $v) => $v * 10)
            ->each(function (int $v) use (&$collected) {
                $collected[] = $v;
                if ($v === 20) return false;
            })
            ->flush();

        // each broke early but map already transformed all values
        $this->assertSame([10, 20], $collected);
        $this->assertSame([10, 20, 30], $list->all());
    }

    // ----------[ persistence ]----------

    public function test_persists_across_instances(): void
    {
        $first = new ConcurrentList('test:list-persist');
        $first->add('shared');

        $second = new ConcurrentList('test:list-persist');
        $this->assertSame('shared', $second->get(0));
    }

    public function test_mixed_types(): void
    {
        $list = new ConcurrentList('test:list-mixed');

        $list->add('string');
        $list->add(42);
        $list->add(['nested' => true]);

        $this->assertSame('string', $list->get(0));
        $this->assertSame(42, $list->get(1));
        $this->assertSame(['nested' => true], $list->get(2));
    }

    public function test_auto_key_as_class_property(): void
    {
        $owner = new TestListOwner;
        $owner->items->add('auto');

        $another = new TestListOwner;
        $this->assertSame('auto', $another->items->get(0));
    }
}

class TestListOwner
{
    public ConcurrentList $items;

    public function __construct()
    {
        $this->items = new ConcurrentList;
    }
}
