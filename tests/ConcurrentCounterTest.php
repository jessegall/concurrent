<?php

namespace JesseGall\Concurrent\Tests;

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
}
