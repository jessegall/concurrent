<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\WithAccessors;

class WithAccessorsTest extends TestCase
{
    // ----------[ get ]----------

    public function test_get_returns_value_from_wrapped(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:get');
        $instance->doBump();

        $this->assertSame(1, $instance->doRead('count', 0));
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:default');

        $this->assertSame('fallback', $instance->doRead('nonexistent', 'fallback'));
    }

    public function test_get_returns_default_when_value_is_null(): void
    {
        // ConcurrentMap's get() uses ?? for the same reason; match that behavior.
        $instance = new BasicAccessorsTarget('test:wa:null-value');
        $instance->doSet('count', null);

        $this->assertSame(99, $instance->doRead('count', 99));
    }

    // ----------[ set ]----------

    public function test_set_writes_to_wrapped(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:set');
        $instance->doSet('count', 42);

        $this->assertSame(42, $instance->count);
    }

    public function test_set_persists_across_instances(): void
    {
        $a = new BasicAccessorsTarget('test:wa:persist');
        $a->doSet('count', 7);

        $b = new BasicAccessorsTarget('test:wa:persist');
        $this->assertSame(7, $b->count);
    }

    // ----------[ has ]----------

    public function test_has_returns_true_for_set_value(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:has-true');
        $instance->doSet('count', 5);

        $this->assertTrue($instance->doHas('count'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:has-false');

        $this->assertFalse($instance->doHas('nope'));
    }

    // ----------[ update ]----------

    public function test_update_with_bound_closure_mutates_via_proxy(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:update-bound');

        $instance->doUpdate(function () {
            /** @var BasicAccessorsTarget $this */
            $this->count = 11;
        });

        $this->assertSame(11, $instance->count);
    }

    public function test_update_with_param_closure_uses_arg_style(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:update-arg');

        $instance->doUpdate(function (BasicAccessorsData $data) {
            $data->count = 22;

            return $data;
        });

        $this->assertSame(22, $instance->count);
    }

    public function test_update_with_reference_param_mutates_in_place(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:update-ref');

        $instance->doUpdate(function (BasicAccessorsData &$data) {
            $data->count = 33;
        });

        $this->assertSame(33, $instance->count);
    }

    // ----------[ clear ]----------

    public function test_clear_forgets_the_value(): void
    {
        $instance = new BasicAccessorsTarget('test:wa:clear');
        $instance->doSet('count', 99);
        $this->assertSame(99, $instance->count);

        $instance->doClear();

        // After clear, next read returns the default (count = 0).
        $this->assertSame(0, $instance->count);
    }

    // ----------[ chaining ]----------

    public function test_set_update_clear_return_static_for_chaining(): void
    {
        // Use the public-visibility variant so we can call directly off the instance.
        $instance = new PublicAccessorsTarget('test:wa:chain');

        $returned = $instance->set('count', 1);
        $this->assertSame($instance, $returned);

        $returned = $instance->update(function () {
            /** @var PublicAccessorsTarget $this */
            $this->count = 5;
        });
        $this->assertSame($instance, $returned);

        // Full chain.
        $instance->set('count', 10)->update(function () {
            /** @var PublicAccessorsTarget $this */
            $this->count++;
        });
        $this->assertSame(11, $instance->get('count'));
    }

    public function test_update_calls_are_atomic(): void
    {
        // Concurrent's __invoke routes through lock()->set, which uses the
        // lock proxy. Verify update() preserves that path by issuing a write
        // and confirming the cache backend saw a put.
        $cache = new LoggingCache;
        Concurrent::useCache($cache);
        Concurrent::useLock(new \JesseGall\Concurrent\Testing\InMemoryLock);

        $instance = new BasicAccessorsTarget('test:wa:update-atomic');
        $cache->reset();

        $instance->doUpdate(function () {
            /** @var BasicAccessorsTarget $this */
            $this->count = 1;
        });

        $puts = array_filter($cache->operations(), fn ($op) => $op['op'] === 'put');
        $this->assertNotEmpty($puts, 'update() should write back to cache');
    }

    // ----------[ visibility ]----------

    public function test_accessors_are_private_by_default(): void
    {
        // Verify via reflection. (External calls don't actually surface a
        // "private method" error — Concurrent's __call swallows them and
        // forwards to the wrapped value, which then fails with "undefined
        // method." Reflection asserts the actual visibility cleanly.)
        foreach (['get', 'set', 'has', 'update', 'clear'] as $method) {
            $reflection = new \ReflectionMethod(BasicAccessorsTarget::class, $method);
            $this->assertTrue(
                $reflection->isPrivate(),
                "{$method}() should be private by default"
            );
        }
    }

    public function test_accessors_can_be_made_public_via_visibility_override(): void
    {
        $instance = new PublicAccessorsTarget('test:wa:public');

        // Visibility check via reflection.
        foreach (['get', 'set', 'has', 'update', 'clear'] as $method) {
            $reflection = new \ReflectionMethod(PublicAccessorsTarget::class, $method);
            $this->assertTrue(
                $reflection->isPublic(),
                "{$method}() should be public after visibility override"
            );
        }

        // Functional check.
        $instance->set('count', 99);
        $this->assertSame(99, $instance->get('count'));
        $this->assertTrue($instance->has('count'));

        $instance->update(function () {
            /** @var PublicAccessorsTarget $this */
            $this->count = 100;
        });
        $this->assertSame(100, $instance->get('count'));
    }

    public function test_partial_visibility_override(): void
    {
        // Only `get` is public; the rest stay private. Proves the override is
        // per-method rather than all-or-nothing.
        $this->assertTrue(
            (new \ReflectionMethod(PartiallyPublicTarget::class, 'get'))->isPublic(),
            'get() should be public via override'
        );

        foreach (['set', 'has', 'update', 'clear'] as $method) {
            $this->assertTrue(
                (new \ReflectionMethod(PartiallyPublicTarget::class, $method))->isPrivate(),
                "{$method}() should remain private"
            );
        }

        // The public one still works functionally.
        $instance = new PartiallyPublicTarget('test:wa:partial');
        $instance->doSet('count', 5);
        $this->assertSame(5, $instance->get('count'));
    }

    // ----------[ real-property precedence ]----------

    public function test_real_subclass_properties_take_precedence_for_get(): void
    {
        // The subclass has $shopId as a real property; the wrapped data also
        // has $shopId. get('shopId') must return the subclass's value.
        $instance = new PropertyPrecedenceTarget('shop-42');

        $this->assertSame('shop-42', $instance->doRead('shopId'));
        $this->assertNotSame('wrapped-data-shop-id', $instance->doRead('shopId'));
    }

    public function test_real_subclass_properties_take_precedence_for_has(): void
    {
        $instance = new PropertyPrecedenceTarget('shop-77');

        $this->assertTrue($instance->doHas('shopId'));
    }
}

// ----------[ test fixtures ]----------

class BasicAccessorsData
{
    public int|null $count = 0;
}

class BasicAccessorsTarget extends Concurrent
{
    use WithAccessors;

    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new BasicAccessorsData,
            ttl: 60,
        );
    }

    public function doBump(): void
    {
        $this->set('count', $this->get('count', 0) + 1);
    }

    public function doRead(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    public function doSet(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function doHas(string $key): bool
    {
        return $this->has($key);
    }

    public function doUpdate(\Closure $fn): void
    {
        $this->update($fn);
    }

    public function doClear(): void
    {
        $this->clear();
    }
}

class PublicAccessorsData
{
    public int $count = 0;
}

class PublicAccessorsTarget extends Concurrent
{
    use WithAccessors {
        get as public;
        set as public;
        has as public;
        update as public;
        clear as public;
    }

    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new PublicAccessorsData,
            ttl: 60,
        );
    }
}

class PartiallyPublicData
{
    public int $count = 0;
}

class PartiallyPublicTarget extends Concurrent
{
    use WithAccessors {
        get as public;
    }

    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new PartiallyPublicData,
            ttl: 60,
        );
    }

    public function doSet(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }
}

class PropertyPrecedenceData
{
    public string $shopId = 'wrapped-data-shop-id';
}

class PropertyPrecedenceTarget extends Concurrent
{
    use WithAccessors;

    public function __construct(public readonly string $shopId)
    {
        parent::__construct(
            key: "test:wa:precedence:{$shopId}",
            default: fn () => new PropertyPrecedenceData,
            ttl: 60,
        );
    }

    public function doRead(string $key): mixed
    {
        return $this->get($key);
    }

    public function doHas(string $key): bool
    {
        return $this->has($key);
    }
}
