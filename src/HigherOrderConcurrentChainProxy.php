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
     * Execute all queued calls inside a single lock.
     */
    private function flush(): void
    {
        if (empty($this->calls))
        {
            return;
        }

        $calls = $this->calls;
        $this->calls = [];

        ($this->target)(function (Concurrent $target) use ($calls) {
            foreach ($calls as [$method, $arguments])
            {
                $target->{$method}(...$arguments);
            }
        }, lock: true);
    }
}
