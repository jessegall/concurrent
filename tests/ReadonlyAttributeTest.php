<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Attributes\ReadonlyMethod;
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Exceptions\ReadonlyViolationException;
use JesseGall\Concurrent\Testing\InMemoryLock;

class ReadonlyAttributeTest extends TestCase
{
    public function test_readonly_attribute_skips_lock_and_write_back(): void
    {
        $cache = new \JesseGall\Concurrent\Tests\LoggingCache;
        Concurrent::useCache($cache);
        Concurrent::useLock(new InMemoryLock);

        $concurrent = new Concurrent(
            key: 'test:readonly-attr',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        // Prime the cache with the default value.
        $concurrent();

        $cache->reset();

        $result = $concurrent->describe();

        $this->assertSame('idle', $result);

        // Read-only methods read the value once and never write it back.
        $operations = $cache->operations();
        $this->assertNotEmpty($operations);
        foreach ($operations as $op) {
            $this->assertNotSame('put', $op['op'], 'Read-only method should not write to cache');
        }
    }

    public function test_readonly_method_that_mutates_throws(): void
    {
        $concurrent = new Concurrent(
            key: 'test:readonly-violation',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        $this->expectException(ReadonlyViolationException::class);
        $this->expectExceptionMessage('is declared read-only but mutated');

        $concurrent->violatesReadonly();
    }

    public function test_non_readonly_method_still_locks_and_writes(): void
    {
        $cache = new LoggingCache;
        Concurrent::useCache($cache);
        Concurrent::useLock(new InMemoryLock);

        $concurrent = new Concurrent(
            key: 'test:writer',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        $concurrent();
        $cache->reset();

        $concurrent->bump();

        $putOps = array_filter(
            $cache->operations(),
            fn (array $op) => $op['op'] === 'put',
        );

        $this->assertNotEmpty($putOps, 'Mutating method should write back to cache');
        $this->assertSame(1, $concurrent->count);
    }

    public function test_readonly_attribute_does_not_lock(): void
    {
        $blocking = new BlockingLock;
        Concurrent::useLock($blocking);

        $concurrent = new Concurrent(
            key: 'test:readonly-no-lock',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        $blocking->hold();

        // If the read-only attribute were ignored, this would attempt to acquire
        // the (already held) lock and throw "Lock is already held".
        $this->assertSame('idle', $concurrent->describe());
    }

    public function test_readonly_violation_message_includes_class_and_method(): void
    {
        $concurrent = new Concurrent(
            key: 'test:violation-message',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        try {
            $concurrent->violatesReadonly();
            $this->fail('Expected ReadonlyViolationException');
        } catch (ReadonlyViolationException $e) {
            $this->assertStringContainsString(ReadonlyAttributeTarget::class, $e->getMessage());
            $this->assertStringContainsString('violatesReadonly', $e->getMessage());
        }
    }

    public function test_readonly_attribute_returns_the_method_result(): void
    {
        $concurrent = new Concurrent(
            key: 'test:readonly-return',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        $this->assertSame('idle', $concurrent->describe());
        $this->assertSame(0, $concurrent->snapshot());
    }

    public function test_method_without_attribute_is_not_readonly(): void
    {
        $concurrent = new Concurrent(
            key: 'test:plain-method',
            default: fn () => new ReadonlyAttributeTarget,
            ttl: 60,
        );

        // bump() is a plain method — calling it should not throw, even though it mutates.
        $concurrent->bump();
        $concurrent->bump();

        $this->assertSame(2, $concurrent->count);
    }

    public function test_interface_declared_method_that_mutates_also_throws(): void
    {
        $concurrent = new ViolatingDeclaredReadOnlyConcurrent('test:declared-violation');

        $this->expectException(ReadonlyViolationException::class);

        $concurrent->mutateOnRead();
    }

    public function test_a_value_that_cannot_be_serialized_is_still_readable(): void
    {
        $concurrent = new Concurrent(
            key: 'test:unserializable-read',
            default: fn () => new UnserializableTarget,
            ttl: 60,
        );

        $this->assertSame('idle', $concurrent->describe());
    }

    public function test_a_value_that_cannot_be_serialized_reports_no_false_violation(): void
    {
        $concurrent = new Concurrent(
            key: 'test:unserializable-mutation',
            default: fn () => new UnserializableTarget,
            ttl: 60,
        );

        // Without a snapshot to compare against the mutation cannot be seen, so the call has
        // to pass rather than fail for the wrong reason.
        $this->assertSame(1, $concurrent->violatesReadonly());
    }

    public function test_non_object_target_ignores_attribute_lookup(): void
    {
        // Attributes only exist on object methods. With a scalar target,
        // the attribute path must be a no-op — and __call should not be
        // hit at all for scalars in normal usage. This guard is here to
        // prove the reflection lookup is safe when the target is not an object.
        $concurrent = new Concurrent(
            key: 'test:scalar-target',
            default: 42,
            ttl: 60,
        );

        $this->assertSame(42, $concurrent());
    }
}

class ReadonlyAttributeTarget
{
    public int $count = 0;

    #[ReadonlyMethod]
    public function describe(): string
    {
        return 'idle';
    }

    #[ReadonlyMethod]
    public function snapshot(): int
    {
        return $this->count;
    }

    #[ReadonlyMethod]
    public function violatesReadonly(): int
    {
        $this->count++;

        return $this->count;
    }

    public function bump(): void
    {
        $this->count++;
    }
}

/**
 * Holds a closure, so PHP refuses to serialize it — the shape of any value carrying a
 * resource handle, a connection, or a test double.
 */
class UnserializableTarget
{
    public int $count = 0;

    public \Closure $onRead;

    public function __construct()
    {
        $this->onRead = static fn (): string => 'idle';
    }

    #[ReadonlyMethod]
    public function describe(): string
    {
        return ($this->onRead)();
    }

    #[ReadonlyMethod]
    public function violatesReadonly(): int
    {
        $this->count++;

        return $this->count;
    }
}

class ViolatingDeclaredReadOnlyConcurrentData
{
    public int $count = 0;

    public function mutateOnRead(): int
    {
        $this->count++;

        return $this->count;
    }
}

class ViolatingDeclaredReadOnlyConcurrent extends Concurrent implements \JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods
{
    public function __construct(string $key)
    {
        parent::__construct(
            key: $key,
            default: fn () => new ViolatingDeclaredReadOnlyConcurrentData,
            ttl: 60,
        );
    }

    public static function readOnlyMethods(): array
    {
        return ['mutateOnRead'];
    }
}
