<?php

namespace JesseGall\Concurrent;

/**
 * Collects method calls and executes them all inside a single lock on destruct.
 *
 * @mixin Concurrent
 */
class HigherOrderConcurrentChainProxy
{
    /** @var list<array{string, array<mixed>}> */
    private array $calls = [];

    public function __construct(
        private readonly Concurrent $target,
    ) {}

    public function __destruct()
    {
        $this->flush();
    }

    public function __call(string $method, array $arguments): self
    {
        $this->calls[] = [$method, $arguments];

        return $this;
    }

    /**
     * Execute all queued calls inside a single lock and return the resulting value.
     */
    public function flush(): mixed
    {
        if (empty($this->calls))
        {
            return ($this->target)();
        }

        $calls = $this->calls;
        $this->calls = [];

        $result = null;

        ($this->target)(function (Concurrent $target) use ($calls, &$result) {
            foreach ($calls as [$method, $arguments])
            {
                $target->{$method}(...$arguments);
            }

            $result = $target();
        }, lock: true);

        return $result;
    }
}
