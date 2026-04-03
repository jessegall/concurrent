<?php

namespace JesseGall\Concurrent;

/**
 * A deferred chain of operations on a ConcurrentList.
 *
 * Collects map, filter, and each operations, then executes them all
 * inside a single lock on destruct — one read, one write.
 */
class ConcurrentListChain
{
    /** @var list<callable(array &$list): void> */
    private array $operations = [];

    public function __construct(
        private readonly ConcurrentList $list,
    ) {}

    public function __destruct()
    {
        $this->flush();
    }

    /**
     * Execute all queued operations inside a single lock and write back.
     */
    public function flush(): void
    {
        if (empty($this->operations))
        {
            return;
        }

        $operations = $this->operations;
        $this->operations = [];

        ($this->list)(function (array &$list) use ($operations) {
            foreach ($operations as $operation)
            {
                $operation($list);
            }
        });
    }

    /**
     * Queue an each operation. Return false from the callback to break early.
     *
     * @param  callable(mixed $value, int $index): mixed  $callback
     */
    public function each(callable $callback): self
    {
        $this->operations[] = function (array &$list) use ($callback) {
            foreach ($list as $index => $value)
            {
                if ($callback($value, $index) === false)
                {
                    break;
                }
            }
        };

        return $this;
    }

    /**
     * Queue a map operation.
     *
     * With & — modify in-place.
     * Without & — return value replaces the item.
     *
     * @param  callable(mixed $value, int $index): mixed  $callback
     */
    public function map(callable $callback): self
    {
        $byReference = CallableInspector::acceptsByReference($callback);

        $this->operations[] = function (array &$list) use ($callback, $byReference) {
            foreach ($list as $index => &$value)
            {
                if ($byReference)
                {
                    $callback($value, $index);
                }
                else
                {
                    $value = $callback($value, $index);
                }
            }
        };

        return $this;
    }

    /**
     * Queue a filter operation.
     *
     * @param  callable(mixed $value, int $index): bool  $callback
     */
    public function filter(callable $callback): self
    {
        $this->operations[] = function (array &$list) use ($callback) {
            $list = array_values(array_filter($list, $callback, ARRAY_FILTER_USE_BOTH));
        };

        return $this;
    }
}
