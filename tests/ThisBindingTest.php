<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;

class ThisBindingTest extends TestCase
{
    public function test_array_element_write_on_object_property(): void
    {
        // The bug report scenario: $this->arrayProp['key'] = X used to fail
        // with "Indirect modification of overloaded property" when $this was
        // bound to the wrapper. With the BoundProxy + &__get, it works.
        $concurrent = new Concurrent(
            key: 'test:this-binding:array-element-write',
            default: fn () => new ThisBindingArrayHolder,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingArrayHolder $this */
            $this->counts['ok'] = 1;
            $this->counts['fail'] = 2;
        });

        $this->assertSame(['ok' => 1, 'fail' => 2], $concurrent->counts);
    }

    public function test_array_append_on_object_property(): void
    {
        // Second bug report scenario: $this->arrayProp[] = X.
        $concurrent = new Concurrent(
            key: 'test:this-binding:array-append',
            default: fn () => new ThisBindingArrayHolder,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingArrayHolder $this */
            $this->affected[] = ['row' => 1, 'reason' => 'duplicate'];
            $this->affected[] = ['row' => 2, 'reason' => 'invalid'];
        });

        $this->assertCount(2, $concurrent->affected);
        $this->assertSame(['row' => 1, 'reason' => 'duplicate'], $concurrent->affected[0]);
        $this->assertSame(['row' => 2, 'reason' => 'invalid'], $concurrent->affected[1]);
    }

    public function test_nested_array_element_write(): void
    {
        // Three levels of nesting — the &__get reference must thread all the way down.
        $concurrent = new Concurrent(
            key: 'test:this-binding:nested-array-write',
            default: fn () => new ThisBindingArrayHolder,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingArrayHolder $this */
            $this->rejectionsByReason['duplicate'][] = 'row-1';
            $this->rejectionsByReason['duplicate'][] = 'row-2';
            $this->rejectionsByReason['invalid'][] = 'row-3';
        });

        $this->assertSame(
            ['duplicate' => ['row-1', 'row-2'], 'invalid' => ['row-3']],
            $concurrent->rejectionsByReason,
        );
    }

    public function test_method_call_on_data_routes_to_data(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:method-on-data',
            default: fn () => new ThisBindingDataWithMethods,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingDataWithMethods $this */
            $this->bump();
            $this->bump();
        });

        $this->assertSame(2, $concurrent->count);
    }

    public function test_self_const_resolves_to_lexical_scope_inside_bound_closure(): void
    {
        // Closures defined inside a method retain their lexical scope, so
        // self::, parent::, and static:: must keep working after we rebind.
        $instance = new ThisBindingScopeSubclass('test:this-binding:scope');

        $instance->captureConstantViaSelf();

        $this->assertSame('expected-value', $instance->captured);
    }

    public function test_method_missing_from_data_falls_back_to_wrapper(): void
    {
        // wrapperOnly() is a method on the Concurrent subclass, not on the data.
        // The proxy must route the call through to the wrapper.
        $concurrent = new SubclassWithWrapperMethod('test:this-binding:wrapper-fallback');

        $concurrent(function () {
            /** @var SubclassWithWrapperMethod $this */
            $this->wrapperOnly('hello-from-wrapper');
        });

        $this->assertSame('hello-from-wrapper', $concurrent->label);
    }

    public function test_property_writes_via_this_route_through_proxy(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:property-write',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        $concurrent(function () {
            $this->count = 42;
            $this->status = 'ready';
        });

        $this->assertSame(42, $concurrent->count);
        $this->assertSame('ready', $concurrent->status);
    }

    public function test_method_calls_via_this_route_through_proxy(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:method-call',
            default: fn () => new ThisBindingDataWithMethods,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingDataWithMethods $this */
            $this->bump();
            $this->bump();
            $this->setLabel('hello');
        });

        $this->assertSame(2, $concurrent->count);
        $this->assertSame('hello', $concurrent->label);
    }

    public function test_no_return_statement_required(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:no-return',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        $concurrent(function () {
            $this->count++;
        });
        $concurrent(function () {
            $this->count++;
        });

        $this->assertSame(2, $concurrent->count);
    }

    public function test_subclass_methods_callable_via_this(): void
    {
        $session = new ThisBindingSubclass('test:this-binding:subclass');

        $session(function () {
            /** @var ThisBindingSubclass $this */
            $this->bumpAndLabel('processing');
        });

        $this->assertSame(1, $session->count);
        $this->assertSame('processing', $session->status);
    }

    public function test_array_target_supports_property_writes_via_proxy(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:array',
            default: fn () => [],
            ttl: 60,
        );

        $concurrent(function () {
            $this->foo = 'bar';
            $this->count = 7;
        });

        $this->assertSame('bar', $concurrent['foo']);
        $this->assertSame(7, $concurrent['count']);
    }

    public function test_closure_with_param_still_uses_arg_style(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:param',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        $concurrent(function (ThisBindingData $data) {
            $data->count = 7;

            return $data;
        });

        $this->assertSame(7, $concurrent->count);
    }

    public function test_closure_with_reference_param_still_works(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:ref',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        $concurrent(function (ThisBindingData &$data) {
            $data->count = 99;
        });

        $this->assertSame(99, $concurrent->count);
    }

    public function test_zero_param_closure_returning_value_stores_it(): void
    {
        // Return-style is preserved for zero-param closures: if the closure
        // returns a non-null value, that value is stored (this is the pattern
        // ConcurrentList::clear() uses: $this(fn () => [])).
        $concurrent = new Concurrent(
            key: 'test:this-binding:return-style',
            default: fn () => ['old'],
            ttl: 60,
        );

        $concurrent(fn () => ['fresh']);

        $this->assertSame(['fresh'], $concurrent());
    }

    public function test_static_closure_falls_through_to_return_style(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:static',
            default: 0,
            ttl: 60,
        );

        // Static closures cannot be rebound — they fall through and behave as
        // a plain return-style callback.
        $concurrent(static fn () => 5);

        $this->assertSame(5, $concurrent());
    }

    public function test_bound_closure_acquires_lock_only_once_for_multiple_mutations(): void
    {
        // Inside a bound zero-param closure, every $this->prop = X and
        // $this->method() routes through __set/__call — each of which would
        // try to acquire the lock. Concurrent's re-entrancy guard must
        // suppress those nested acquisitions, leaving only the outer one
        // from __invoke. Verify directly with a counting lock driver.
        $lockLog = [];

        $lock = new class ($lockLog) implements \JesseGall\Concurrent\Contracts\LockDriver {
            /** @var array<int, string> */
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

        Concurrent::useCache(new \JesseGall\Concurrent\Testing\InMemoryCache);
        Concurrent::useLock($lock);

        $concurrent = new Concurrent(
            key: 'test:this-binding:single-lock',
            default: fn () => new ThisBindingDataWithMethods,
            ttl: 60,
        );

        // Prime cache (this acquires + releases its own lock; clear log after).
        $concurrent(function () {});
        $lockLog = [];

        $concurrent(function () {
            /** @var ThisBindingDataWithMethods $this */
            $this->count = 1;        // would normally acquire lock #2
            $this->bump();           // would normally acquire lock #3
            $this->setLabel('hi');   // would normally acquire lock #4
            $this->label = 'final';  // would normally acquire lock #5
        });

        $this->assertSame(
            ['acquire', 'release'],
            $lockLog,
            'Bound closure must acquire the lock exactly once, even with multiple inner mutations'
        );

        // Sanity: the mutations did happen.
        $this->assertSame(2, $concurrent->count);     // 1 + bump()
        $this->assertSame('final', $concurrent->label);
    }

    public function test_bound_closure_with_increment_acquires_lock_only_once(): void
    {
        // $this->count++ desugars to __get + __set, which would each try to
        // touch the lock proxy. With the re-entrancy guard, multiple ++ ops
        // inside one bound closure should still produce a single outer acquire.
        $lockLog = [];

        $lock = new class ($lockLog) implements \JesseGall\Concurrent\Contracts\LockDriver {
            /** @var array<int, string> */
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

        Concurrent::useCache(new \JesseGall\Concurrent\Testing\InMemoryCache);
        Concurrent::useLock($lock);

        $concurrent = new Concurrent(
            key: 'test:this-binding:increment-single-lock',
            default: fn () => new ThisBindingDataWithMethods,
            ttl: 60,
        );

        $concurrent(function () {});
        $lockLog = [];

        $concurrent(function () {
            /** @var ThisBindingDataWithMethods $this */
            $this->count++;
            $this->count++;
            $this->count++;
        });

        $this->assertSame(
            ['acquire', 'release'],
            $lockLog,
            '++ inside bound closure must not acquire additional locks'
        );
        $this->assertSame(3, $concurrent->count);
    }

    public function test_bound_closure_persists_across_calls(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:persists',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        for ($i = 0; $i < 5; $i++) {
            $concurrent(function () {
                $this->count++;
            });
        }

        $this->assertSame(5, $concurrent->count);
    }
}

class ThisBindingData
{
    public function __construct(
        public int $count = 0,
        public string $status = 'idle',
    ) {}
}

class ThisBindingDataWithMethods
{
    public int $count = 0;
    public string $label = '';

    public function bump(): void
    {
        $this->count++;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }
}

class ThisBindingSubclassData
{
    public int $count = 0;
    public string $status = 'idle';
}

class ThisBindingSubclass extends Concurrent
{
    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new ThisBindingSubclassData,
            ttl: 60,
        );
    }

    public function bumpAndLabel(string $status): void
    {
        $this(function () use ($status) {
            /** @var ThisBindingSubclass $this */
            $this->count++;
            $this->status = $status;
        });
    }
}

class ThisBindingArrayHolder
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<array{row: int, reason: string}> */
    public array $affected = [];

    /** @var array<string, list<string>> */
    public array $rejectionsByReason = [];
}

class ThisBindingScopeSubclassData
{
    public string $captured = '';
}

class ThisBindingScopeSubclass extends Concurrent
{
    private const string EXPECTED = 'expected-value';

    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new ThisBindingScopeSubclassData,
            ttl: 60,
        );
    }

    public function captureConstantViaSelf(): void
    {
        $this(function () {
            // self:: must still resolve to ThisBindingScopeSubclass after rebinding.
            $this->captured = self::EXPECTED;
        });
    }
}

class SubclassWithWrapperMethodData
{
    public string $label = '';
}

class SubclassWithWrapperMethod extends Concurrent
{
    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new SubclassWithWrapperMethodData,
            ttl: 60,
        );
    }

    public function wrapperOnly(string $label): void
    {
        $this->label = $label;
    }
}
