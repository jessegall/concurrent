<?php

namespace JesseGall\Concurrent;

use Closure;

/**
 * @mixin Concurrent
 */
class HigherOrderConcurrentLockProxy
{
    public function __construct(
        private Concurrent $target,
        private Closure $lock,
    ) {}

    public function __call(string $method, array $arguments)
    {
        return $this->lock(fn () => $this->{$method}(...$arguments));
    }

    public function __get(string $property)
    {
        return $this->lock(fn () => $this->{$property});
    }

    private function lock(Closure $callback): mixed
    {
        return ($this->lock)(fn () => $this->execute($callback));
    }

    private function execute(Closure $callback): mixed
    {
        $callback = $callback->bindTo($this->target, Concurrent::class);

        $result = $callback();

        if ($result === $this->target)
        {
            return $this;
        }

        return $result;
    }
}
