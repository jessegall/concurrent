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

    public function test_reads_value_with_callback(): void
    {
        $concurrent = new Concurrent('test:read', default: 0, ttl: 60);

        $concurrent(42);

        $result = $concurrent(fn ($value) => $value * 2, read: true);
        $this->assertSame(84, $result);

        // Original value unchanged
        $this->assertSame(42, $concurrent());
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
        $session = new class implements DeclaresReadOnlyMethods {
            public int $writeCount = 0;

            public static function readOnlyMethods(): array
            {
                return ['getStatus'];
            }

            public function setStatus(string $status): void
            {
                $this->writeCount++;
            }

            public function getStatus(): string
            {
                return 'active';
            }
        };

        $concurrent = new Concurrent('test:readonly', default: fn () => clone $session, ttl: 60);

        // Writer method — triggers lock + write
        $concurrent->setStatus('ready');

        // Reader method — skips lock + write
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

    public function test_concurrent_api_trait_methods(): void
    {
        $store = new TestConcurrentWithApi('test:api', default: fn () => [], ttl: 60);

        $store->set(['a', 'b']);
        $this->assertSame(['a', 'b'], $store->get());

        $store->update(function (&$v) {
            $v[] = 'c';
        });
        $this->assertSame(['a', 'b', 'c'], $store->get());

        $pulled = $store->pull();
        $this->assertSame(['a', 'b', 'c'], $pulled);
        $this->assertSame([], $store->get()); // back to default

        $store->set(['x']);
        $store->forget();
        $this->assertSame([], $store->get()); // back to default
    }

    public function test_with_lock_executes_callback_atomically(): void
    {
        $store = new TestConcurrentWithApi('test:withlock', default: 0, ttl: 60);
        $store->set(5);

        $result = $store->withLock(fn ($v) => $v * 3);
        $this->assertSame(15, $result);

        // Value unchanged — withLock is read-only
        $this->assertSame(5, $store->get());
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
}

class TestConcurrentWithApi extends Concurrent
{
    use \JesseGall\Concurrent\Concerns\ConcurrentApi;
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
