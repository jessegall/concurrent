<?php

namespace JesseGall\Concurrent;

trait ForwardsCallsToTarget
{
    /**
     * Forward a method call to the given object, rethrowing errors as BadMethodCallException
     * with the calling class name for clearer stack traces.
     */
    private function forwardCallTo(mixed $object, string $method, array $parameters): mixed
    {
        try
        {
            return $object->{$method}(...$parameters);
        }
        catch (\Error|\BadMethodCallException $e)
        {
            if (! preg_match('~^Call to undefined method (?P<class>[^:]+)::(?P<method>[^\(]+)\(\)$~', $e->getMessage(), $matches))
            {
                throw $e;
            }

            if ($matches['class'] !== get_class($object) || $matches['method'] !== $method)
            {
                throw $e;
            }

            throw new \BadMethodCallException(
                sprintf('Call to undefined method %s::%s()', static::class, $method)
            );
        }
    }

    /**
     * Forward a method call, returning $this instead of the target when the target returns itself.
     * This preserves fluent chaining on the wrapper.
     */
    private function forwardDecoratedCallTo(mixed $object, string $method, array $parameters): mixed
    {
        $result = $this->forwardCallTo($object, $method, $parameters);

        return $result === $object ? $this : $result;
    }
}
