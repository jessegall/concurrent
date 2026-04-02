<?php

namespace JesseGall\Concurrent;

use Closure;
use ReflectionClass;
use RuntimeException;

/**
 * A concurrent value that automatically generates its cache key from the class and property it belongs to.
 *
 * When used as a class property, it inspects the owning class via reflection to build a unique key.
 * For example, a `$stats` property on `App\Models\User` gets the key "App\Models\User:stats".
 *
 * This is useful for inter-process communication — different processes or queue jobs can
 * share data through the cache using the same automatically-generated keys without
 * having to manually coordinate key names.
 *
 * @template TValue
 *
 * @extends Concurrent<TValue>
 */
class ConcurrentClassMember extends Concurrent
{
    protected const int MAX_BACKTRACE_DEPTH = 10;

    /**
     * The source object from which the ConcurrentValue is initialized.
     */
    private mixed $source;

    /**
     * A flag indicating whether the key has been generated.
     */
    private bool $keyGenerated = false;

    /**
     * The key for the cached value, generated from the source object's class name
     * and the property that holds a reference to this ConcurrentValue instance.
     */
    public protected(set) string $key {
        get {
            // Key generation is deferred until first access because during object construction
            // this instance hasn't been assigned to its property yet, making it invisible to reflection.

            if ($this->keyGenerated)
            {
                return $this->key;
            }

            $key = $this->generateKeyFromSourceProperty();
            $this->keyGenerated = true;

            return $this->key = $key;
        }
    }

    /**
     * @param  string|array<string>|null  $tags
     */
    public function __construct(
        mixed $default = null,
        int $ttl = 300,
        Closure|null $validator = null,
        string|array|null $tags = null,
    ) {
        parent::__construct('temp', $default, $ttl, $validator, $tags);

        $this->source = $this->resolveSource();
    }

    /**
     * Resolve the source object of the ConcurrentValue instance.
     *
     * @throws RuntimeException
     */
    private function resolveSource(): mixed
    {
        $source = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, self::MAX_BACKTRACE_DEPTH);

        foreach ($source as $item)
        {
            $object = $item['object'] ?? null;

            if ($object && $object != $this)
            {
                return $object;
            }
        }

        return throw new RuntimeException('Unable to resolve source object for ConcurrentMember.');
    }

    /**
     * Generate a unique key based on the source object's class name and the
     * property that holds a reference to this ConcurrentMemberValue instance.
     *
     * @throws \ReflectionException
     */
    private function generateKeyFromSourceProperty(): string
    {
        $reflector = new ReflectionClass($this->source);

        foreach ($reflector->getProperties() as $property)
        {
            if ($property->isStatic())
            {
                continue;
            }

            if (! $property->isInitialized($this->source))
            {
                continue;
            }

            $value = $property->getValue($this->source);

            if ($value === $this)
            {
                return "{$reflector->getName()}:{$property->getName()}";
            }
        }

        throw new RuntimeException('Unable to determine target key from source properties.');
    }
}
