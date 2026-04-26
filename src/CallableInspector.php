<?php

namespace JesseGall\Concurrent;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;

class CallableInspector
{
    /**
     * Check if the callable's first parameter is a reference (&$param).
     */
    public static function acceptsByReference(callable $callable): bool
    {
        $ref = new ReflectionFunction($callable);

        return $ref->getNumberOfParameters() > 0
            && $ref->getParameters()[0]->isPassedByReference();
    }

    /**
     * Check if the callable is a non-static, zero-parameter closure that
     * should be invoked with $this rebound to the wrapped value.
     */
    public static function shouldBindThis(mixed $callable): bool
    {
        if (! $callable instanceof Closure) {
            return false;
        }

        $ref = new ReflectionFunction($callable);

        return ! $ref->isStatic() && $ref->getNumberOfParameters() === 0;
    }

    /**
     * Get the type name of a parameter at the given index, or null if untyped.
     */
    public static function parameterType(callable $callable, int $index): string|null
    {
        $ref = new ReflectionFunction($callable);
        $params = $ref->getParameters();

        if (! isset($params[$index]))
        {
            return null;
        }

        $type = $params[$index]->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * Check if a parameter at the given index has the specified type.
     */
    public static function isParameterType(callable $callable, int $index, string $type): bool
    {
        return self::parameterType($callable, $index) === $type;
    }
}
