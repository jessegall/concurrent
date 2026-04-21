<?php

namespace JesseGall\Concurrent\Tests;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JesseGall\Concurrent\ConcurrentCounter;

class ConcurrentCounterTest extends TestCase
{
    public function test_increment(): void
    {
        $counter = new ConcurrentCounter('test:counter-inc');

        $counter->increment();
        $counter->increment();
        $counter->increment();

        $this->assertSame(3, $counter->count());
    }

    public function test_increment_by_amount(): void
    {
        $counter = new ConcurrentCounter('test:counter-inc-amount');

        $counter->increment(5);
        $counter->increment(3);

        $this->assertSame(8, $counter->count());
    }

    public function test_decrement(): void
    {
        $counter = new ConcurrentCounter('test:counter-dec');

        $counter->increment(10);
        $counter->decrement();
        $counter->decrement();

        $this->assertSame(8, $counter->count());
    }

    public function test_decrement_by_amount(): void
    {
        $counter = new ConcurrentCounter('test:counter-dec-amount');

        $counter->increment(10);
        $counter->decrement(4);

        $this->assertSame(6, $counter->count());
    }

    public function test_decrement_below_zero(): void
    {
        $counter = new ConcurrentCounter('test:counter-negative');

        $counter->decrement(5);

        $this->assertSame(-5, $counter->count());
    }

    public function test_validator_rejects_cached_value_below_min(): void
    {
        Cache::put('test:counter-validator-min', -10, 3600);

        $counter = new ConcurrentCounter('test:counter-validator-min', min: 0, max: 100);

        $this->assertSame(0, $counter->count());
    }

    public function test_validator_rejects_cached_value_above_max(): void
    {
        Cache::put('test:counter-validator-max', 500, 3600);

        $counter = new ConcurrentCounter('test:counter-validator-max', min: 0, max: 100);

        $this->assertSame(0, $counter->count());
    }

    public function test_reset(): void
    {
        $counter = new ConcurrentCounter('test:counter-reset');

        $counter->increment(42);
        $counter->reset();

        $this->assertSame(0, $counter->count());
    }

    public function test_default_is_zero(): void
    {
        $counter = new ConcurrentCounter('test:counter-default');

        $this->assertSame(0, $counter->count());
    }

    public function test_persists_across_instances(): void
    {
        $first = new ConcurrentCounter('test:counter-persist');
        $first->increment(7);

        $second = new ConcurrentCounter('test:counter-persist');
        $this->assertSame(7, $second->count());
    }

    public function test_increment_by_zero(): void
    {
        $counter = new ConcurrentCounter('test:counter-zero-inc');

        $counter->increment(5);
        $counter->increment(0);

        $this->assertSame(5, $counter->count());
    }

    public function test_decrement_by_zero(): void
    {
        $counter = new ConcurrentCounter('test:counter-zero-dec');

        $counter->increment(5);
        $counter->decrement(0);

        $this->assertSame(5, $counter->count());
    }

    public function test_auto_key_as_class_property(): void
    {
        $owner = new TestCounterOwner;
        $owner->hits->increment(3);

        $another = new TestCounterOwner;
        $this->assertSame(3, $another->hits->count());
    }

    // ----------[ Bounds: min / max clamp ]----------

    public function test_min_clamps_decrement_at_lower_bound(): void
    {
        $counter = new ConcurrentCounter('test:counter-min-clamp', min: 0);

        $counter->decrement(5);

        $this->assertSame(0, $counter->count());
    }

    public function test_max_clamps_increment_at_upper_bound(): void
    {
        $counter = new ConcurrentCounter('test:counter-max-clamp', max: 10);

        $counter->increment(100);

        $this->assertSame(10, $counter->count());
    }

    public function test_clamp_with_both_bounds(): void
    {
        $counter = new ConcurrentCounter('test:counter-both-clamp', min: 0, max: 10);

        $counter->increment(100);
        $this->assertSame(10, $counter->count());

        $counter->decrement(100);
        $this->assertSame(0, $counter->count());
    }

    public function test_clamp_within_bounds_is_noop(): void
    {
        $counter = new ConcurrentCounter('test:counter-in-range', min: 0, max: 100);

        $counter->increment(42);

        $this->assertSame(42, $counter->count());
    }

    public function test_default_starts_at_min_when_set(): void
    {
        $counter = new ConcurrentCounter('test:counter-min-default', min: 10);

        $this->assertSame(10, $counter->count());
    }

    public function test_reset_goes_to_min_when_set(): void
    {
        $counter = new ConcurrentCounter('test:counter-reset-min', min: 5);

        $counter->increment(20);
        $counter->reset();

        $this->assertSame(5, $counter->count());
    }

    // ----------[ Bounds: wrap / modulo ]----------

    public function test_wrap_over_max_rolls_to_min(): void
    {
        $counter = new ConcurrentCounter('test:counter-wrap-over', min: 0, max: 10, wrap: true);

        $counter->increment(11);

        $this->assertSame(0, $counter->count());
    }

    public function test_wrap_under_min_rolls_to_max(): void
    {
        $counter = new ConcurrentCounter('test:counter-wrap-under', min: 0, max: 10, wrap: true);

        $counter->decrement(1);

        $this->assertSame(10, $counter->count());
    }

    public function test_wrap_handles_large_overflow(): void
    {
        // 0..5 range is 6 values; incrementing by 13 wraps 2 full cycles + 1.
        $counter = new ConcurrentCounter('test:counter-wrap-large', min: 0, max: 5, wrap: true);

        $counter->increment(13);

        $this->assertSame(1, $counter->count());
    }

    public function test_wrap_with_non_zero_min(): void
    {
        // 5..10 range is 6 values (5,6,7,8,9,10).
        $counter = new ConcurrentCounter('test:counter-wrap-offset', min: 5, max: 10, wrap: true);

        $counter->increment(2);
        $this->assertSame(7, $counter->count());

        $counter->increment(4);
        $this->assertSame(5, $counter->count());
    }

    public function test_wrap_requires_max(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConcurrentCounter('test:counter-wrap-no-max', min: 0, wrap: true);
    }

    public function test_wrap_defaults_min_to_zero_when_only_max_given(): void
    {
        $counter = new ConcurrentCounter('test:counter-wrap-max-only', max: 5, wrap: true);

        $counter->increment(6);
        $this->assertSame(0, $counter->count());

        $counter->increment(8);
        $this->assertSame(2, $counter->count());

        $counter->decrement(3);
        $this->assertSame(5, $counter->count());
    }

    public function test_min_greater_than_max_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConcurrentCounter('test:counter-bad-bounds', min: 10, max: 5);
    }

    public function test_single_step_wrap(): void
    {
        // A 3-faced die: 0, 1, 2.
        $counter = new ConcurrentCounter('test:counter-die', min: 0, max: 2, wrap: true);

        $counter->increment();
        $this->assertSame(1, $counter->count());

        $counter->increment();
        $this->assertSame(2, $counter->count());

        $counter->increment();
        $this->assertSame(0, $counter->count());
    }
}

class TestCounterOwner
{
    public ConcurrentCounter $hits;

    public function __construct()
    {
        $this->hits = new ConcurrentCounter;
    }
}
