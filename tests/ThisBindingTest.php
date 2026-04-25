<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;

class ThisBindingTest extends TestCase
{
    public function test_zero_param_closure_binds_this_to_wrapped_object(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:basic',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingData $this */
            $this->count = 42;
            $this->status = 'ready';
        });

        $this->assertSame(42, $concurrent->count);
        $this->assertSame('ready', $concurrent->status);
    }

    public function test_no_return_statement_is_not_required(): void
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

    public function test_bound_closure_can_access_private_members(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:private',
            default: fn () => new ThisBindingDataWithPrivate,
            ttl: 60,
        );

        $concurrent(function () {
            /** @var ThisBindingDataWithPrivate $this */
            $this->setSecret('opened');
        });

        $resolved = $concurrent();
        $this->assertSame('opened', $resolved->reveal());
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

    public function test_static_closure_falls_through_to_return_style(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:static',
            default: fn () => new ThisBindingData,
            ttl: 60,
        );

        // A static closure cannot be rebound — it must fall through and behave
        // like a plain return-style callback.
        $concurrent(static fn () => (new ThisBindingData(count: 5)));

        $this->assertSame(5, $concurrent->count);
    }

    public function test_zero_param_closure_with_array_target_falls_through(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:array',
            default: fn () => [],
            ttl: 60,
        );

        // No object to bind to — the closure's return value is stored.
        $concurrent(fn () => ['a', 'b', 'c']);

        $this->assertSame(['a', 'b', 'c'], $concurrent());
    }

    public function test_zero_param_closure_with_scalar_target_falls_through(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:scalar',
            default: 0,
            ttl: 60,
        );

        $concurrent(fn () => 42);

        $this->assertSame(42, $concurrent());
    }

    public function test_anonymous_class_target_supports_binding(): void
    {
        $concurrent = new Concurrent(
            key: 'test:this-binding:anon',
            default: fn () => new class {
                public int $x = 0;
            },
            ttl: 60,
        );

        $concurrent(function () {
            $this->x = 11;
        });

        $this->assertSame(11, $concurrent->x);
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

class ThisBindingDataWithPrivate
{
    private string $secret = 'closed';

    public function setSecret(string $value): void
    {
        $this->secret = $value;
    }

    public function reveal(): string
    {
        return $this->secret;
    }
}
