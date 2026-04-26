<?php

namespace JesseGall\Concurrent;

use Closure;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Queues operations and runs them all in one lock when the chain ends
 * (terminal call, explicit flush(), or destruct).
 *
 * @mixin Concurrent
 */
class HigherOrderConcurrentChainProxy
{
    /** @var list<Closure> */
    private array $queued = [];

    public function __construct(
        private readonly Concurrent $target,
    ) {}

    public function __destruct()
    {
        if ($this->queued !== [])
        {
            $this->flush();
        }
    }

    public function queue(Closure $fn): self
    {
        $this->queued[] = $fn;

        return $this;
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (! method_exists($this->target, $method))
        {
            $this->flush();

            return $this->target->{$method}(...$arguments);
        }

        if ($this->returnsChainProxy($method))
        {
            $next = $this->target->{$method}(...$arguments);

            if ($next instanceof self)
            {
                array_push($this->queued, ...$next->queued);
                $next->queued = [];
            }

            return $this;
        }

        $this->flush();

        return $this->target->{$method}(...$arguments);
    }

    public function flush(): mixed
    {
        if ($this->queued === [])
        {
            return ($this->target)();
        }

        $queued = $this->queued;
        $this->queued = [];

        $result = null;

        ($this->target)(function () use ($queued, &$result) {
            foreach ($queued as $fn)
            {
                $fn();
            }

            $result = $this();
        });

        return $result;
    }

    private function returnsChainProxy(string $method): bool
    {
        $type = (new ReflectionMethod($this->target, $method))->getReturnType();

        if (! $type instanceof ReflectionNamedType)
        {
            return false;
        }

        return $type->getName() === self::class;
    }
}
