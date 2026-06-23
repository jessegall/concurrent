<?php

namespace JesseGall\Concurrent\Exceptions;

use RuntimeException;

/**
 * Thrown when the cache store hands back an incomplete object because it
 * was configured to reject unserializing classes.
 *
 * Laravel's database cache store passes the `cache.serializable_classes`
 * config to unserialize() as the allowed-classes restriction. The published
 * config defaults this to `false`, so every cached object comes back as a
 * __PHP_Incomplete_Class and the next method call on it fails with an opaque
 * error. We surface the cause here instead, with the fix.
 */
class RestrictedCacheException extends RuntimeException
{
    public static function forIncompleteClass(string $key, string $class): self
    {
        return new self(
            "Cache key [$key] returned an incomplete object for class [$class]. "
            . "The cache store is configured to reject unserializing classes. "
            . "Add [$class] to the 'cache.serializable_classes' array in config/cache.php "
            . "(or set it to true to allow all classes)."
        );
    }
}
