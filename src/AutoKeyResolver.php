<?php

namespace JesseGall\Concurrent;

use JesseGall\Concurrent\Contracts\KeyResolver;
use ReflectionClass;
use RuntimeException;

/**
 * Derives a cache key for a Concurrent built without an explicit `key:`.
 * At construction it captures the owning class's __construct frame from
 * the backtrace; on resolve() it reflects on that owner to find which
 * property holds the Concurrent instance, then returns "FQCN:property".
 *
 * @internal
 */
final class AutoKeyResolver implements KeyResolver
{
    private const int MAX_BACKTRACE_DEPTH = 10;

    private readonly object $source;
    private string|null $resolved = null;

    public function __construct(private readonly object $owner)
    {
        $this->source = $this->findOwningConstructor();
    }

    public function resolve(): string
    {
        return $this->resolved ??= $this->doResolve();
    }

    private function doResolve(): string
    {
        $reflector = new ReflectionClass($this->source);

        foreach ($reflector->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->isInitialized($this->source)) {
                continue;
            }

            if ($property->getValue($this->source) === $this->owner) {
                return "{$reflector->getName()}:{$property->getName()}";
            }
        }

        throw new RuntimeException($this->errorMessage('Unable to auto-resolve cache key.'));
    }

    private function findOwningConstructor(): object
    {
        $stack = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, self::MAX_BACKTRACE_DEPTH);

        foreach ($stack as $frame) {
            $object = $frame['object'] ?? null;
            $function = $frame['function'] ?? null;

            if ($object
                && $object !== $this
                && $object !== $this->owner
                && $function === '__construct'
            ) {
                return $object;
            }
        }

        throw new RuntimeException($this->errorMessage('No key provided.'));
    }

    private function errorMessage(string $lead): string
    {
        return $lead
            . ' Pass a key: new ' . $this->owner::class . '(key: "my-key").'
            . ' A key can only be omitted when created inside a class constructor as a property.';
    }
}
