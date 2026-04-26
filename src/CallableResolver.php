<?php

namespace JesseGall\Concurrent;

use Closure;
use ReflectionFunction;

/**
 * Resolves a user callback against the wrapped value into a value to persist.
 *
 * @internal
 */
final class CallableResolver
{
    public function __construct(private readonly Concurrent $concurrent) {}

    /**
     * Dispatch on shape and return [resolved value, write?]. `write?` is
     * false only when a bound-$this closure touched nothing and returned null.
     *
     * @return array{0: mixed, 1: bool}
     */
    public function resolve(callable $callable, mixed $current): array
    {
        if (CallableInspector::shouldBindThis($callable)) {
            return $this->resolveBoundThis($callable, $current);
        }

        if (CallableInspector::acceptsByReference($callable)) {
            $callable($current);

            return [$current, true];
        }

        return [$callable($current), true];
    }

    /**
     * Bind $this to a BoundProxy over $current and run. Mutations land on
     * $current via the proxy; an explicit return wins if nothing was touched.
     *
     * @return array{0: mixed, 1: bool}
     */
    private function resolveBoundThis(Closure $callable, mixed $current): array
    {
        $proxy = new BoundProxy($current, $this->concurrent);

        $scope = new ReflectionFunction($callable)->getClosureScopeClass()?->getName();
        $bound = Closure::bind($callable, $proxy, $scope);

        $result = $bound();

        if ($proxy->touchedData) {
            return [$current, true];
        }

        if ($result !== null) {
            return [$result, true];
        }

        return [null, false];
    }
}
