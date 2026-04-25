<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\ConcurrentPointer;
use JesseGall\Concurrent\WithPointer;

class ConcurrentPointerTest extends TestCase
{
    public function test_start_mints_an_id_and_claims_pointer(): void
    {
        $pointer = $this->makePointer();

        $instance = $pointer->start();

        $this->assertInstanceOf(PointerTarget::class, $instance);
        $this->assertNotEmpty($instance->id);
        $this->assertSame($instance->id, $pointer->id());
    }

    public function test_start_with_explicit_id_uses_that_id(): void
    {
        $pointer = $this->makePointer();

        $instance = $pointer->start('run-123');

        $this->assertSame('run-123', $instance->id);
        $this->assertSame('run-123', $pointer->id());
    }

    public function test_resolve_returns_null_when_unstarted(): void
    {
        $pointer = $this->makePointer();

        $this->assertNull($pointer->resolve());
    }

    public function test_resolve_returns_instance_after_start(): void
    {
        $pointer = $this->makePointer();

        $started = $pointer->start('run-abc');
        $resolved = $pointer->resolve();

        $this->assertNotNull($resolved);
        $this->assertInstanceOf(PointerTarget::class, $resolved);
        $this->assertSame($started->id, $resolved->id);
    }

    public function test_resolved_instance_shares_state_with_originator(): void
    {
        $pointer = $this->makePointer();

        $started = $pointer->start('run-shared');
        $started->bump();
        $started->bump();

        $resolved = $pointer->resolve();

        $this->assertSame(2, $resolved->getCount());
    }

    public function test_release_clears_pointer(): void
    {
        $pointer = $this->makePointer();

        $pointer->start('run-tmp');
        $this->assertNotNull($pointer->resolve());

        $pointer->release();

        $this->assertNull($pointer->resolve());
        $this->assertNull($pointer->id());
    }

    public function test_start_overwrites_an_existing_pointer(): void
    {
        $pointer = $this->makePointer();

        $first = $pointer->start('first');
        $second = $pointer->start('second');

        $this->assertSame('first', $first->id);
        $this->assertSame('second', $second->id);
        $this->assertSame('second', $pointer->id());
    }

    public function test_custom_id_generator_is_used(): void
    {
        $pointer = new ConcurrentPointer(
            key: 'test:pointer:custom-gen',
            factory: fn (string $id) => new PointerTarget($id),
            generateId: fn () => 'fixed-id',
        );

        $instance = $pointer->start();

        $this->assertSame('fixed-id', $instance->id);
    }

    public function test_factory_receives_the_id(): void
    {
        $received = null;
        $pointer = new ConcurrentPointer(
            key: 'test:pointer:factory-arg',
            factory: function (string $id) use (&$received) {
                $received = $id;

                return new PointerTarget($id);
            },
        );

        $pointer->start('passed-through');

        $this->assertSame('passed-through', $received);
    }

    public function test_default_id_generator_produces_unique_values(): void
    {
        $pointer = $this->makePointer();

        $a = $pointer->start();
        $b = $pointer->start();

        $this->assertNotSame($a->id, $b->id);
    }

    // ----------[ WithPointer trait ]----------

    public function test_trait_provides_pointer_with_class_derived_key(): void
    {
        $pointer = TraitTarget::pointer();

        $this->assertInstanceOf(ConcurrentPointer::class, $pointer);

        $instance = TraitTarget::start();

        $this->assertInstanceOf(TraitTarget::class, $instance);
        $this->assertNotEmpty($instance->id);

        // Pointer key is derived from the class — different traited class must not collide.
        $this->assertNull(OtherTraitTarget::current());
    }

    public function test_trait_current_returns_null_when_unstarted(): void
    {
        $this->assertNull(TraitTarget::current());
    }

    public function test_trait_current_resolves_after_start(): void
    {
        $started = TraitTarget::start();
        $resolved = TraitTarget::current();

        $this->assertNotNull($resolved);
        $this->assertSame($started->id, $resolved->id);
    }

    public function test_trait_release_clears_pointer(): void
    {
        TraitTarget::start();
        $this->assertNotNull(TraitTarget::current());

        TraitTarget::release();

        $this->assertNull(TraitTarget::current());
    }

    public function test_trait_start_auto_generates_unique_ids(): void
    {
        $a = TraitTarget::start();
        $b = TraitTarget::start();

        $this->assertNotEmpty($a->id);
        $this->assertNotEmpty($b->id);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_trait_pointer_key_can_be_overridden(): void
    {
        $expectedKey = 'custom:stable-pointer';
        $instance = CustomKeyTarget::start();

        // Read directly from cache to verify the override took effect.
        $stored = cache()->get($expectedKey);
        $this->assertSame($instance->id, $stored);
    }

    public function test_trait_generate_id_can_be_overridden(): void
    {
        $instance = FixedIdTarget::start();

        $this->assertSame('fixed-id-from-override', $instance->id);
    }

    public function test_trait_spreads_extra_args_to_constructor(): void
    {
        $started = MultiArgTarget::start('tenant-A', 7);

        $this->assertSame('tenant-A', $started->tenant);
        $this->assertSame(7, $started->shard);
        $this->assertNotEmpty($started->id);

        // Resolving without extras would call the constructor with just the ID,
        // which fails because the other args are required — supply them again.
        $resolved = MultiArgTarget::current('tenant-A', 7);

        $this->assertNotNull($resolved);
        $this->assertSame($started->id, $resolved->id);
        $this->assertSame('tenant-A', $resolved->tenant);
        $this->assertSame(7, $resolved->shard);
    }

    public function test_trait_supports_constructor_where_id_is_not_first_arg(): void
    {
        $started = ReorderedArgsTarget::start('tenant-A');

        $this->assertSame('tenant-A', $started->tenant);
        $this->assertNotEmpty($started->runId);

        $resolved = ReorderedArgsTarget::current('tenant-A');

        $this->assertNotNull($resolved);
        $this->assertSame('tenant-A', $resolved->tenant);
        $this->assertSame($started->runId, $resolved->runId);
    }

    public function test_pointer_spreads_extra_args(): void
    {
        $pointer = new ConcurrentPointer(
            key: 'test:pointer:multi-arg-' . uniqid('', true),
            factory: fn (string $id, string $tenant, int $shard) => new MultiArgTarget($id, $tenant, $shard),
        );

        $started = $pointer->start(null, 'acme', 3);
        $this->assertSame('acme', $started->tenant);
        $this->assertSame(3, $started->shard);

        $resolved = $pointer->resolve('acme', 3);
        $this->assertSame($started->id, $resolved->id);
        $this->assertSame('acme', $resolved->tenant);
    }

    private function makePointer(): ConcurrentPointer
    {
        return new ConcurrentPointer(
            key: 'test:pointer:' . uniqid('', true),
            factory: fn (string $id) => new PointerTarget($id),
        );
    }
}

class PointerTargetData
{
    public int $count = 0;
}

class PointerTarget extends Concurrent
{
    public function __construct(public readonly string $id)
    {
        parent::__construct(
            key: "test-pointer-target:{$id}",
            default: fn () => new PointerTargetData,
        );
    }

    public function bump(): void
    {
        $this(function (PointerTargetData $data) {
            $data->count++;

            return $data;
        });
    }

    public function getCount(): int
    {
        return $this->count;
    }
}

class TraitTarget extends Concurrent
{
    use WithPointer;

    public function __construct(public readonly string $id)
    {
        parent::__construct(
            key: "trait-target:{$id}",
            default: fn () => new \stdClass,
        );
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id);
    }
}

class OtherTraitTarget extends Concurrent
{
    use WithPointer;

    public function __construct(public readonly string $id)
    {
        parent::__construct(
            key: "other-trait-target:{$id}",
            default: fn () => new \stdClass,
        );
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id);
    }
}

class CustomKeyTarget extends Concurrent
{
    use WithPointer;

    public function __construct(public readonly string $id)
    {
        parent::__construct(
            key: "custom-key-target:{$id}",
            default: fn () => new \stdClass,
        );
    }

    protected static function pointerKey(): string
    {
        return 'custom:stable-pointer';
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id);
    }
}

class FixedIdTarget extends Concurrent
{
    use WithPointer;

    public function __construct(public readonly string $id)
    {
        parent::__construct(
            key: "fixed-id-target:{$id}",
            default: fn () => new \stdClass,
        );
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id);
    }

    protected static function generateId(): string
    {
        return 'fixed-id-from-override';
    }
}

class MultiArgTarget extends Concurrent
{
    use WithPointer;

    public function __construct(
        public readonly string $id,
        public readonly string $tenant,
        public readonly int $shard,
    ) {
        parent::__construct(
            key: "multi-arg-target:{$tenant}:{$shard}:{$id}",
            default: fn () => new \stdClass,
        );
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id, $args[0], $args[1]);
    }
}

/**
 * Constructor where the ID is NOT the first arg — exists to prove that
 * fromPointerId() lets the user wire any constructor shape.
 */
class ReorderedArgsTarget extends Concurrent
{
    use WithPointer;

    public function __construct(
        public readonly string $tenant,
        public readonly string $runId,
    ) {
        parent::__construct(
            key: "reordered:{$tenant}:{$runId}",
            default: fn () => new \stdClass,
        );
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        // $id is the runId; tenant comes from $args.
        return new static($args[0], $id);
    }
}
