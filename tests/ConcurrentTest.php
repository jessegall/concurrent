<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

class ConcurrentTest extends TestCase
{
    public function test_stores_and_retrieves_scalar_value(): void
    {
        $concurrent = new Concurrent('test:scalar', default: 0, ttl: 60);

        $concurrent(42);

        $this->assertSame(42, $concurrent());
    }

    public function test_returns_default_on_cache_miss(): void
    {
        $concurrent = new Concurrent('test:default', default: 'fallback', ttl: 60);

        $this->assertSame('fallback', $concurrent());
    }

    public function test_default_can_be_callable(): void
    {
        $concurrent = new Concurrent('test:callable-default', default: fn () => ['empty'], ttl: 60);

        $this->assertSame(['empty'], $concurrent());
    }

    public function test_forgets_value_on_null(): void
    {
        $concurrent = new Concurrent('test:forget', default: 'initial', ttl: 60);

        $concurrent('stored');
        $this->assertSame('stored', $concurrent());

        $concurrent(null);
        $this->assertSame('initial', $concurrent());
    }

    public function test_sets_value_with_callable(): void
    {
        $concurrent = new Concurrent('test:callable-set', default: 10, ttl: 60);

        $concurrent(10);
        $concurrent(fn ($current) => $current + 5);

        $this->assertSame(15, $concurrent());
    }

    public function test_sets_value_with_reference_callable(): void
    {
        $concurrent = new Concurrent('test:ref-set', default: 10, ttl: 60);

        $concurrent(10);
        $concurrent(function (&$current) {
            $current += 5;
        });

        $this->assertSame(15, $concurrent());
    }

    public function test_reference_callable_with_object(): void
    {
        $obj = new class {
            public int $count = 0;
        };

        $concurrent = new Concurrent('test:ref-obj', default: fn () => clone $obj, ttl: 60);

        $concurrent(function (&$data) {
            $data->count = 42;
        });

        $this->assertSame(42, $concurrent->count);
    }

    public function test_reference_callable_with_array(): void
    {
        $concurrent = new Concurrent('test:ref-array', default: fn () => [], ttl: 60);

        $concurrent(function (&$data) {
            $data['key'] = 'value';
            $data['items'][] = 'first';
        });

        $this->assertSame('value', $concurrent['key']);
        $this->assertSame(['first'], $concurrent['items']);
    }

    public function test_reference_callable_with_arrow_function(): void
    {
        $concurrent = new Concurrent('test:ref-arrow', default: 10, ttl: 60);

        $concurrent(10);
        $concurrent(fn (&$current) => $current += 5);

        $this->assertSame(15, $concurrent());
    }

    public function test_reference_arrow_function_array_append_on_object(): void
    {
        $obj = new class {
            public array $items = [];
        };

        $concurrent = new Concurrent('test:ref-arrow-append-obj', default: fn () => clone $obj, ttl: 60);

        $concurrent(fn (&$data) => $data->items[] = 'first');
        $concurrent(fn (&$data) => $data->items[] = 'second');

        $this->assertSame(['first', 'second'], $concurrent->items);
    }

    public function test_reference_arrow_function_array_append_on_array(): void
    {
        $concurrent = new Concurrent('test:ref-arrow-append-arr', default: fn () => [], ttl: 60);

        $concurrent(fn (&$data) => $data[] = 'first');
        $concurrent(fn (&$data) => $data[] = 'second');

        $this->assertSame(['first', 'second'], $concurrent());
    }

    public function test_get_does_not_lock(): void
    {
        $concurrent = new Concurrent(key: 'test:get-no-lock', default: 42, ttl: 60);
        $concurrent(42);

        // Acquire the lock externally
        $lock = \Illuminate\Support\Facades\Cache::lock('test:get-no-lock:lock', 10);
        $lock->get();

        // Get should still work even though the lock is held
        $this->assertSame(42, $concurrent());

        $lock->release();
    }

    public function test_write_blocks_when_lock_is_held(): void
    {
        $concurrent = new Concurrent(key: 'test:write-blocks', default: 0, ttl: 60);

        // Acquire the lock externally — simulates another process holding it
        $lock = \Illuminate\Support\Facades\Cache::lock('test:write-blocks:lock', 10);
        $lock->get();

        // Write should block and eventually throw because the lock can't be acquired
        $this->expectException(\Illuminate\Contracts\Cache\LockTimeoutException::class);

        $concurrent(42);
    }

    public function test_read_only_method_does_not_lock(): void
    {
        $concurrent = new TestReadOnlyConcurrent('test:readonly-no-lock');
        $concurrent->setStatus('ready');

        // Acquire the lock externally
        $lock = \Illuminate\Support\Facades\Cache::lock('test:readonly-no-lock:lock', 10);
        $lock->get();

        // Read-only method should still work
        $result = $concurrent->getStatus();
        $this->assertSame('active', $result);

        $lock->release();
    }

    public function test_proxies_method_calls_to_wrapped_object(): void
    {
        $counter = new class {
            public int $count = 0;

            public function increment(): void
            {
                $this->count++;
            }

            public function getCount(): int
            {
                return $this->count;
            }
        };

        $concurrent = new Concurrent('test:proxy', default: fn () => clone $counter, ttl: 60);

        $concurrent->increment();
        $concurrent->increment();
        $concurrent->increment();

        $this->assertSame(3, $concurrent->getCount());
    }

    public function test_proxies_property_access_to_wrapped_object(): void
    {
        $obj = new class {
            public string $name = 'initial';
        };

        $concurrent = new Concurrent('test:property', default: fn () => clone $obj, ttl: 60);

        $concurrent->name = 'updated';

        $this->assertSame('updated', $concurrent->name);
    }

    public function test_works_with_array_values(): void
    {
        $concurrent = new Concurrent('test:array', default: fn () => [], ttl: 60);

        $concurrent['key'] = 'value';

        $this->assertTrue(isset($concurrent['key']));
        $this->assertSame('value', $concurrent['key']);

        unset($concurrent['key']);
        $this->assertFalse(isset($concurrent['key']));
    }

    public function test_iterable_with_array(): void
    {
        $concurrent = new Concurrent('test:iterable', default: fn () => [1, 2, 3], ttl: 60);

        $items = [];
        foreach ($concurrent as $item)
        {
            $items[] = $item;
        }

        $this->assertSame([1, 2, 3], $items);
    }

    public function test_validates_value_on_set(): void
    {
        $concurrent = new Concurrent(
            key: 'test:validated',
            default: 0,
            ttl: 60,
            validator: fn ($v) => is_int($v) && $v >= 0,
        );

        $concurrent(5);
        $this->assertSame(5, $concurrent());

        $this->expectException(\InvalidArgumentException::class);
        $concurrent(-1);
    }

    public function test_read_only_methods_skip_lock_and_write(): void
    {
        $concurrent = new TestReadOnlyConcurrent('test:readonly');

        // Writer method — triggers lock + write
        $concurrent->setStatus('ready');

        // Reader method — no lock, no write
        $result = $concurrent->getStatus();
        $this->assertSame('active', $result);
    }

    public function test_wraps_object_with_multiple_fields(): void
    {
        $data = new class {
            public string|null $activity = null;

            /** @var array<string, string> */
            public array $skipped = [];

            public function setActivity(string $text): void
            {
                $this->activity = $text;
            }

            public function skip(string $key, string $reason): void
            {
                $this->skipped[$key] = $reason;
            }

            public function clearActivity(): void
            {
                $this->activity = null;
            }
        };

        $concurrent = new Concurrent('test:session', default: fn () => clone $data, ttl: 60);

        $concurrent->setActivity('Importing products...');
        $this->assertSame('Importing products...', $concurrent->activity);

        $concurrent->skip('webhook', 'Not in production');
        $this->assertSame(['webhook' => 'Not in production'], $concurrent->skipped);

        $concurrent->clearActivity();
        $this->assertNull($concurrent->activity);
    }

    public function test_invalid_cached_value_falls_back_to_default(): void
    {
        $concurrent = new Concurrent(
            key: 'test:invalid-read',
            default: 'safe',
            ttl: 60,
            validator: fn ($v) => is_string($v),
        );

        // Manually put an invalid value in cache
        cache()->put('test:invalid-read', 123, 60);

        // Should fall back to default since 123 is not a string
        $this->assertSame('safe', $concurrent());
    }

    public function test_isset_and_unset_on_object_properties(): void
    {
        $obj = new class {
            public string|null $name = 'test';
        };

        $concurrent = new Concurrent('test:isset', default: fn () => clone $obj, ttl: 60);

        $this->assertTrue(isset($concurrent->name));

        $concurrent->name = null;
        $this->assertNull($concurrent->name);
    }

    public function test_iterating_non_iterable_throws(): void
    {
        $concurrent = new Concurrent('test:non-iterable', default: 42, ttl: 60);
        $concurrent(42);

        $this->expectException(\InvalidArgumentException::class);

        foreach ($concurrent as $item)
        {
            // should not reach here
        }
    }

    public function test_different_keys_are_isolated(): void
    {
        $a = new Concurrent('test:isolated-a', default: 0, ttl: 60);
        $b = new Concurrent('test:isolated-b', default: 0, ttl: 60);

        $a(10);
        $b(20);

        $this->assertSame(10, $a());
        $this->assertSame(20, $b());
    }

    public function test_throws_when_no_key_and_not_in_constructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No key provided');

        // Created without a key outside a constructor — fails immediately
        new Concurrent(default: 'value', ttl: 60);
    }

    public function test_throws_when_no_key_in_regular_method(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No key provided');

        $obj = new TestCreatesInMethod;
        $obj->create();
    }

    public function test_array_append_with_null_key(): void
    {
        $concurrent = new Concurrent('test:array-append', default: fn () => [], ttl: 60);

        $concurrent[] = 'first';
        $concurrent[] = 'second';

        $this->assertSame(['first', 'second'], $concurrent());
    }

    public function test_unset_on_object_property(): void
    {
        $obj = new class {
            public string|null $name = 'hello';
        };

        $concurrent = new Concurrent('test:unset-obj', default: fn () => clone $obj, ttl: 60);

        unset($concurrent->name);
        $this->assertNull($concurrent->name);
    }

    public function test_unset_on_array_key(): void
    {
        $concurrent = new Concurrent('test:unset-array', default: fn () => ['a' => 1, 'b' => 2], ttl: 60);

        unset($concurrent['a']);
        $this->assertFalse(isset($concurrent['a']));
        $this->assertTrue(isset($concurrent['b']));
    }

    public function test_get_property_on_non_object_non_array_returns_null(): void
    {
        $concurrent = new Concurrent('test:scalar-prop', default: 42, ttl: 60);
        $concurrent(42);

        $this->assertNull($concurrent->anything);
    }

    public function test_reentrant_lock_does_not_deadlock(): void
    {
        $obj = new class {
            public int $value = 0;

            public function incrementTwice(): void
            {
                $this->value += 2;
            }
        };

        $concurrent = new Concurrent('test:reentrant', default: fn () => clone $obj, ttl: 60);

        // __invoke with callback acquires lock, then calls set() which is already inside the lock
        $concurrent(function ($data) {
            $data->value = 10;

            return $data;
        });

        $this->assertSame(10, $concurrent->value);
    }

    public function test_subclass_with_declares_read_only_methods(): void
    {
        $session = new TestCsvProcessingSession('upload-123');

        // Writers — lock + write
        $session->start(1000);
        $this->assertSame(1000, $session->totalRows);
        $this->assertSame('processing', $session->status);

        $session->advanceRow();
        $session->advanceRow();
        $session->advanceRow();

        $session->step('Validating rows...');
        $session->reportError(42, 'Invalid email');

        // Readers — no lock, no write-back
        $this->assertSame(0, $session->getProgress()); // 3/1000 rounds to 0
        $this->assertSame('processing', $session->getStatus());
        $this->assertSame('Validating rows...', $session->getCurrentStep());
        $this->assertSame(['Row 42: Invalid email'], $session->getErrors());

        $session->complete();
        $this->assertSame('completed', $session->getStatus());
        $this->assertNull($session->getCurrentStep());
    }

    public function test_subclass_read_only_methods_do_not_persist_mutations(): void
    {
        $session = new TestCsvProcessingSession('upload-readonly');

        $session->start(10);
        $session->advanceRow();

        // getProgress is read-only — even if the underlying object were mutated
        // during the call, it would NOT be written back to cache
        $progress = $session->getProgress();
        $this->assertSame(10, $progress);

        // State is unchanged
        $this->assertSame(1, $session->processedRows);
    }

    public function test_property_increment_via_proxy(): void
    {
        $obj = new class {
            public int $count = 0;
        };

        $concurrent = new Concurrent('test:increment', default: fn () => clone $obj, ttl: 60);

        $concurrent->count++;
        $concurrent->count++;
        $concurrent->count++;

        $this->assertSame(3, $concurrent->count);
    }

    public function test_subclass_wraps_object_with_custom_methods(): void
    {
        $progress = new TestImportProgress('shop-123');

        $progress->start(100);
        $this->assertSame(100, $progress->total);
        $this->assertSame('processing', $progress->status);

        $progress->advance();
        $progress->advance();
        $progress->advance();
        $this->assertSame(3, $progress->imported);
        $this->assertSame(3, $progress->percentage());

        $progress->complete();
        $this->assertSame('completed', $progress->status);
    }

    public function test_subclass_state_persists_across_instances(): void
    {
        $first = new TestImportProgress('shop-persist');
        $first->start(50);
        $first->advance();

        // New instance with same key sees the same state
        $second = new TestImportProgress('shop-persist');
        $this->assertSame(50, $second->total);
        $this->assertSame(1, $second->imported);
        $this->assertSame('processing', $second->status);
    }

    public function test_concurrent_hash_map(): void
    {
        $limiter = new TestRateLimiter;

        $limiter->hit('192.168.1.1');
        $limiter->hit('192.168.1.1');
        $limiter->hit('192.168.1.1');
        $limiter->hit('10.0.0.1');

        $this->assertSame(3, $limiter->getAttempts('192.168.1.1'));
        $this->assertSame(1, $limiter->getAttempts('10.0.0.1'));
        $this->assertFalse($limiter->isLimited('192.168.1.1'));
    }

    public function test_concurrent_hash_map_persists_across_instances(): void
    {
        $first = new TestRateLimiter;
        $first->hit('192.168.1.1');
        $first->hit('192.168.1.1');

        // New instance — same auto-generated key, same cached state
        $second = new TestRateLimiter;
        $this->assertSame(2, $second->getAttempts('192.168.1.1'));
    }

    public function test_concurrent_hash_map_reset(): void
    {
        $limiter = new TestRateLimiter;
        $limiter->hit('192.168.1.1');
        $limiter->hit('192.168.1.1');

        $limiter->reset('192.168.1.1');

        $this->assertSame(0, $limiter->getAttempts('192.168.1.1'));
    }
}

class TestImportProgressData
{
    public int $imported = 0;
    public int $total = 0;
    public string $status = 'pending';

    public function percentage(): int
    {
        return $this->total > 0
            ? (int) round(($this->imported / $this->total) * 100)
            : 0;
    }
}

/**
 * @mixin TestImportProgressData
 */
class TestImportProgress extends Concurrent
{
    public function __construct(string $shopId)
    {
        parent::__construct(
            key: "test:import-progress:{$shopId}",
            default: fn () => new TestImportProgressData(),
            ttl: 300,
        );
    }

    /**
     * Start the import with the given total.
     * Uses __invoke to atomically update multiple fields at once.
     */
    public function start(int $total): void
    {
        $this(function (TestImportProgressData $data) use ($total) {
            $data->total = $total;
            $data->status = 'processing';

            return $data;
        });
    }

    /**
     * Advance the imported count by one.
     */
    public function advance(): void
    {
        $this(function (TestImportProgressData $data) {
            $data->imported++;

            return $data;
        });
    }

    /**
     * Mark the import as completed.
     */
    public function complete(): void
    {
        $this->status = 'completed';
    }
}

class TestCsvProcessingSessionData
{
    public int $processedRows = 0;
    public int $totalRows = 0;
    public string $status = 'pending';
    public string|null $currentStep = null;
    public array $rowErrors = [];

    public function getProgress(): int
    {
        return $this->totalRows > 0
            ? (int) round(($this->processedRows / $this->totalRows) * 100)
            : 0;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrentStep(): string|null
    {
        return $this->currentStep;
    }

    public function getErrors(): array
    {
        return $this->rowErrors;
    }
}

/**
 * @mixin TestCsvProcessingSessionData
 */
class TestCsvProcessingSession extends Concurrent implements DeclaresReadOnlyMethods
{
    public function __construct(string $uploadId)
    {
        parent::__construct(
            key: "test:csv-processing:{$uploadId}",
            default: fn () => new TestCsvProcessingSessionData(),
            ttl: 3600,
        );
    }

    public static function readOnlyMethods(): array
    {
        return ['getProgress', 'getStatus', 'getCurrentStep', 'getErrors'];
    }

    public function start(int $totalRows): void
    {
        $this(function (TestCsvProcessingSessionData $data) use ($totalRows) {
            $data->totalRows = $totalRows;
            $data->status = 'processing';

            return $data;
        });
    }

    public function advanceRow(): void
    {
        $this(function (TestCsvProcessingSessionData $data) {
            $data->processedRows++;

            return $data;
        });
    }

    public function step(string $name): void
    {
        $this->currentStep = $name;
    }

    public function reportError(int $row, string $message): void
    {
        $this(function (TestCsvProcessingSessionData $data) use ($row, $message) {
            $data->rowErrors[] = "Row {$row}: {$message}";

            return $data;
        });
    }

    public function complete(): void
    {
        $this(function (TestCsvProcessingSessionData $data) {
            $data->status = 'completed';
            $data->currentStep = null;

            return $data;
        });
    }
}

class TestConcurrentMap extends Concurrent
{
    public function __construct()
    {
        parent::__construct(
            default: fn () => [],
            ttl: 3600,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this(function (array $map) use ($key, $value) {
            $map[$key] = $value;

            return $map;
        });
    }

    public function remove(string $key): void
    {
        $this(function (array $map) use ($key) {
            unset($map[$key]);

            return $map;
        });
    }

    public function has(string $key): bool
    {
        return isset($this[$key]);
    }
}

class TestRateLimiter
{
    private TestConcurrentMap $attempts;

    public function __construct()
    {
        $this->attempts = new TestConcurrentMap();
    }

    public function hit(string $ip): void
    {
        $this->attempts->set($ip, $this->attempts->get($ip, 0) + 1);
    }

    public function isLimited(string $ip): bool
    {
        return $this->attempts->get($ip, 0) >= 10;
    }

    public function getAttempts(string $ip): int
    {
        return $this->attempts->get($ip, 0);
    }

    public function reset(string $ip): void
    {
        $this->attempts->remove($ip);
    }
}

class TestCreatesInMethod
{
    public function create(): Concurrent
    {
        return new Concurrent(default: 'value', ttl: 60);
    }
}

class TestReadOnlyData
{
    public int $writeCount = 0;

    public function setStatus(string $status): void
    {
        $this->writeCount++;
    }

    public function getStatus(): string
    {
        return 'active';
    }
}

class TestReadOnlyConcurrent extends Concurrent implements DeclaresReadOnlyMethods
{
    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new TestReadOnlyData,
            ttl: 60,
        );
    }

    public static function readOnlyMethods(): array
    {
        return ['getStatus'];
    }
}

