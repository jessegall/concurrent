<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\ConcurrentQueue;

class ConcurrentQueueTest extends TestCase
{
    public function test_push_and_pop(): void
    {
        $queue = new ConcurrentQueue('test:queue-push-pop');

        $queue->push('first');
        $queue->push('second');
        $queue->push('third');

        $this->assertSame('first', $queue->pop());
        $this->assertSame('second', $queue->pop());
        $this->assertSame('third', $queue->pop());
    }

    public function test_pop_returns_null_when_empty(): void
    {
        $queue = new ConcurrentQueue('test:queue-empty-pop');

        $this->assertNull($queue->pop());
    }

    public function test_peek(): void
    {
        $queue = new ConcurrentQueue('test:queue-peek');

        $queue->push('first');
        $queue->push('second');

        $this->assertSame('first', $queue->peek());
        // Peek doesn't remove
        $this->assertSame('first', $queue->peek());
        $this->assertSame(2, $queue->size());
    }

    public function test_peek_returns_null_when_empty(): void
    {
        $queue = new ConcurrentQueue('test:queue-empty-peek');

        $this->assertNull($queue->peek());
    }

    public function test_size(): void
    {
        $queue = new ConcurrentQueue('test:queue-size');

        $queue->push('a');
        $queue->push('b');

        $this->assertSame(2, $queue->size());

        $queue->pop();

        $this->assertSame(1, $queue->size());
    }

    public function test_is_empty(): void
    {
        $queue = new ConcurrentQueue('test:queue-empty');

        $this->assertTrue($queue->isEmpty());

        $queue->push('item');

        $this->assertFalse($queue->isEmpty());
    }

    public function test_clear(): void
    {
        $queue = new ConcurrentQueue('test:queue-clear');

        $queue->push('a');
        $queue->push('b');
        $queue->clear();

        $this->assertTrue($queue->isEmpty());
        $this->assertSame(0, $queue->size());
    }

    public function test_fifo_order(): void
    {
        $queue = new ConcurrentQueue('test:queue-fifo');

        $queue->push(1);
        $queue->push(2);
        $queue->push(3);

        $order = [];
        while (! $queue->isEmpty()) {
            $order[] = $queue->pop();
        }

        $this->assertSame([1, 2, 3], $order);
    }

    public function test_persists_across_instances(): void
    {
        $first = new ConcurrentQueue('test:queue-persist');
        $first->push('shared');

        $second = new ConcurrentQueue('test:queue-persist');
        $this->assertSame('shared', $second->pop());
    }

    public function test_push_mixed_types(): void
    {
        $queue = new ConcurrentQueue('test:queue-mixed');

        $queue->push('string');
        $queue->push(42);
        $queue->push(['key' => 'value']);

        $this->assertSame('string', $queue->pop());
        $this->assertSame(42, $queue->pop());
        $this->assertSame(['key' => 'value'], $queue->pop());
    }

    public function test_auto_key_as_class_property(): void
    {
        $owner = new TestQueueOwner;
        $owner->events->push('event-1');

        $another = new TestQueueOwner;
        $this->assertSame('event-1', $another->events->pop());
    }
}

class TestQueueOwner
{
    public ConcurrentQueue $events;

    public function __construct()
    {
        $this->events = new ConcurrentQueue;
    }
}
