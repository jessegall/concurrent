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
}
