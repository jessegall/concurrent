<?php

namespace JesseGall\Concurrent;

use ArrayAccess;
use InvalidArgumentException;
use IteratorAggregate;
use JesseGall\Concurrent\Contracts\CacheDriver;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;
use JesseGall\Concurrent\Contracts\LockDriver;
use ReflectionClass;
use RuntimeException;
use Traversable;

/**
 * A thread-safe wrapper for cached values.
 *
 * This class lets you work with cached values as if they were the wrapped
 * value itself, while automatically handling caching, validation, and locking
 * when accessing or modifying the value.
 *
 * @template TValue
 *
 * @mixin TValue
 */
class Concurrent implements ArrayAccess, IteratorAggregate
{
    use ForwardsCallsToTarget;

    /**
     * Maximum depth for debug_backtrace when resolving the owning class for auto-key generation.
     */
    private const int MAX_BACKTRACE_DEPTH = 10;

    /**
     * Default cache driver. When set, all new instances use this instead of
     * resolving from the constructor or Laravel container.
     */
    private static CacheDriver|null $defaultCache = null;

    /**
     * Default lock driver. When set, all new instances use this instead of
     * resolving from the constructor or Laravel container.
     */
    private static LockDriver|null $defaultLock = null;

    /**
     * The default value returned on cache miss. Can be a callable for lazy resolution.
     *
     * @var TValue|callable(): TValue
     */
    private readonly mixed $default;

    /**
     * Cache TTL in seconds.
     */
    private readonly int $ttl;

    /**
     * Maximum seconds a distributed lock is held before auto-release.
     * Also used as the timeout when waiting to acquire the lock.
     */
    private readonly int $lockDuration;

    /**
     * Validates values before they are stored. Invalid values on write throw,
     * invalid values on read fall back to the default.
     */
    private readonly ConcurrentValueValidator $validator;

    /**
     * The cache backend for storing and retrieving values.
     */
    private readonly CacheDriver $cacheDriver;

    /**
     * The distributed lock backend for write synchronization.
     */
    private readonly LockDriver $lockDriver;

    /**
     * Re-entrancy guard — prevents deadlocks when a write triggers another write
     * on the same instance within the same lock cycle.
     */
    private bool $isLocked = false;

    /**
     * The object that owns this instance as a property, used for auto-key resolution.
     */
    private mixed $source = null;

    /**
     * Whether the cache key has been resolved (lazily generated or explicitly set).
     */
    private bool $keyResolved = false;

    /**
     * The cache key. When set in the constructor, used as-is.
     * When omitted, lazily generated from the owning class and property name.
     */
    private string $key {
        get {
            if ($this->keyResolved) {
                return $this->key;
            }

            $key = $this->resolveKeyFromSourceProperty();
            $this->keyResolved = true;

            return $this->key = $key;
        }
    }

    /**
     * @param string|null $key Explicit cache key. When null, auto-generated from the owning class and property name.
     * @param TValue|callable(): TValue $default Default value on cache miss. Callables are resolved lazily.
     * @param int $ttl Cache TTL in seconds.
     * @param callable(TValue): bool|null $validator Optional validator. Rejects invalid writes (throws) and invalid reads (falls back to default).
     * @param Cache|null $cache Cache backend. When null, resolved from Laravel's container.
     * @param Lock|null $lock Lock backend. When null, resolved from Laravel's container.
     * @param int $lockDuration Maximum seconds a lock is held before auto-release.
     */
    public function __construct(
        string|null      $key = null,
        mixed            $default = null,
        int              $ttl = 300,
        callable|null    $validator = null,
        CacheDriver|null $cache = null,
        LockDriver|null  $lock = null,
        int              $lockDuration = 10,
    )
    {
        $this->default = $default;
        $this->ttl = $ttl;
        $this->lockDuration = $lockDuration;
        $this->validator = new ConcurrentValueValidator($validator);
        $this->cacheDriver = $cache ?? $this->resolveDefaultCache();
        $this->lockDriver = $lock ?? $this->resolveDefaultLock();

        if ($key !== null) {
            $this->key = $key;
            $this->keyResolved = true;
        } else {
            $this->source = $this->resolveSource();
        }
    }

    // ----------[ Global Driver Configuration ]----------

    /**
     * Set the global cache driver for all new Concurrent instances.
     */
    public static function useCache(CacheDriver $cache): void
    {
        self::$defaultCache = $cache;
    }

    /**
     * Set the global lock driver for all new Concurrent instances.
     */
    public static function useLock(LockDriver $lock): void
    {
        self::$defaultLock = $lock;
    }

    /**
     * Reset global driver overrides to default resolution.
     */
    public static function resetDrivers(): void
    {
        self::$defaultCache = null;
        self::$defaultLock = null;
    }

    // ----------[ Invoke ]----------

    /**
     * Get, set, or forget the cached value.
     *
     * - No arguments: returns the current value (no lock)
     * - Null: clears the value
     * - Callable: executes with current value, stores the result (use &$param for by-reference)
     * - Other: stores the value directly
     *
     * @param  TValue|callable(TValue): TValue|null  $value
     * @param  bool  $lock  When true, acquires a lock and passes $this to the callable.
     * @return TValue|void
     */
    public function __invoke(mixed $value = null, bool $lock = false)
    {
        if (func_num_args() === 0)
        {
            return $this->get();
        }

        if (is_null($value))
        {
            $this->lock()->forget();

            return;
        }

        if ($lock)
        {
            if (! is_callable($value))
            {
                throw new InvalidArgumentException('Lock mode requires a callable.');
            }

            return $this->lock(fn () => $value($this));
        }

        $this->lock()->set($value);
    }

    // ----------[ Magic Methods ]----------

    /**
     * Proxy method calls to the wrapped value.
     * Read-only methods (via DeclaresReadOnlyMethods) skip locking.
     * All other methods acquire a lock, call the method, and write back.
     */
    public function __call(string $name, array $arguments)
    {
        if ($this->isReadOnlyMethod($name)) {
            return $this->forwardDecoratedCallTo($this->get(), $name, $arguments);
        }

        return $this->lock()->call($name, $arguments);
    }

    /**
     * Read a property from the wrapped value (no lock).
     */
    public function __get(string $name)
    {
        return $this->getProperty($name);
    }

    /**
     * Write a property on the wrapped value (acquires lock).
     */
    public function __set(string $name, mixed $value): void
    {
        $this->lock()->setProperty($name, $value);
    }

    /**
     * Check if a property exists on the wrapped value (no lock).
     */
    public function __isset(string $name): bool
    {
        return $this->isset($name);
    }

    /**
     * Unset a property on the wrapped value (acquires lock).
     */
    public function __unset(string $name): void
    {
        $this->lock()->unset($name);
    }

    // ----------[ ArrayAccess ]----------

    public function offsetExists(mixed $offset): bool
    {
        return $this->isset($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getProperty($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->lock()->setProperty($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->lock()->unset($offset);
    }

    // ----------[ IteratorAggregate ]----------

    public function getIterator(): Traversable
    {
        $value = $this->get();

        return match (true) {
            is_array($value) => new \ArrayIterator($value),
            $value instanceof Traversable => $value,
            default => throw new InvalidArgumentException('Cached value is not iterable.'),
        };
    }

    // ----------[ Cache ]----------

    /**
     * Read the cached value. Falls back to default if missing or invalid.
     *
     * @return TValue
     */
    private function get(): mixed
    {
        $value = $this->cacheDriver->get($this->key, fn() => $this->resolveDefaultValue());

        if ($this->validator->invalid($value)) {
            $value = $this->resolveDefaultValue();
            $this->forget();
        }

        return $value;
    }

    /**
     * Read a single property from the wrapped value (array key or object property).
     */
    private function getProperty(string $key): mixed
    {
        $target = $this->get();

        return match (true) {
            is_array($target) => $target[$key] ?? null,
            is_object($target) => $target->{$key} ?? null,
            default => null,
        };
    }

    /**
     * Write a value to cache. Callables are resolved first — if the first parameter
     * is a reference, the value is modified in-place without needing a return.
     *
     * @throws InvalidArgumentException If the validator rejects the value.
     */
    private function set(mixed $value = null): void
    {
        if (is_callable($value) && !is_string($value)) {
            if ($this->acceptsByReference($value)) {
                $current = $this->get();
                $value($current);
                $value = $current;
            } else {
                $value = $value($this->get());
            }
        }

        if ($this->validator->invalid($value)) {
            throw new InvalidArgumentException('Invalid value provided for ConcurrentValue.');
        }

        $this->cacheDriver->put($this->key, $value, $this->ttl);
    }

    /**
     * Write a single property on the wrapped value (array key or object property).
     */
    private function setProperty(string|null $key, mixed $value): void
    {
        $target = $this->get();

        if (is_array($target)) {
            if (is_null($key)) {
                $target[] = $value;
            } else {
                $target[$key] = $value;
            }
        } elseif (is_object($target)) {
            $target->{$key} = $value;
        }

        $this->set($target);
    }

    /**
     * Remove the value from cache.
     */
    private function forget(): void
    {
        $this->cacheDriver->forget($this->key);
    }

    // ----------[ Locking ]----------

    /**
     * Acquire a distributed lock for thread-safe write operations.
     *
     * Without a callback, returns a proxy for chained method calls within the lock.
     * With a callback, executes it within the lock and returns the result.
     * Re-entrant: nested calls within the same lock cycle skip acquisition.
     *
     * @return HigherOrderConcurrentLockProxy|mixed
     */
    private function lock(callable|null $callback = null): mixed
    {
        if (is_null($callback)) {
            return new HigherOrderConcurrentLockProxy($this, fn(callable $callback) => $this->lock($callback));
        }

        if ($this->isLocked) {
            return $callback();
        }

        try {
            $this->isLocked = true;

            return $this->lockDriver->acquire(
                "$this->key:lock",
                $this->lockDuration,
                $this->lockDuration,
                $callback
            );
        } finally {
            $this->isLocked = false;
        }
    }

    // ----------[ Helpers ]----------

    /**
     * Resolve the default value, calling it if it's a callable.
     *
     * @return TValue
     */
    private function resolveDefaultValue(): mixed
    {
        return is_callable($this->default) ? ($this->default)() : $this->default;
    }

    /**
     * Call a method on the wrapped value within a lock, then write back.
     */
    private function call(string $name, array $arguments): mixed
    {
        $target = $this->get();
        $result = $this->forwardDecoratedCallTo($target, $name, $arguments);
        $this->set($target);

        return $result;
    }

    /**
     * Unset a property on the wrapped value (array key or object property).
     */
    private function unset(string $property): void
    {
        $target = $this->get();

        if (is_array($target)) {
            unset($target[$property]);
        } elseif (is_object($target)) {
            unset($target->{$property});
        }

        $this->set($target);
    }

    /**
     * Check if a property exists on the wrapped value.
     */
    private function isset(string $property): bool
    {
        $target = $this->get();

        return match (true) {
            is_array($target) => isset($target[$property]),
            is_object($target) => isset($target->{$property}),
            default => false,
        };
    }

    /**
     * Check if the method is declared as read-only on this Concurrent subclass.
     */
    private function isReadOnlyMethod(string $name): bool
    {
        return $this instanceof DeclaresReadOnlyMethods
            && in_array($name, static::readOnlyMethods(), true);
    }

    /**
     * Check if the callable's first parameter is a reference (&$param).
     * Used to determine whether to pass the value by reference for in-place modification.
     */
    private function acceptsByReference(callable $callable): bool
    {
        return CallableInspector::acceptsByReference($callable);
    }

    // ----------[ Default Resolution ]----------

    /**
     * Resolve the default cache backend from Laravel's container.
     *
     * @throws RuntimeException If Laravel is not available.
     */
    private function resolveDefaultCache(): CacheDriver
    {
        if (self::$defaultCache !== null) {
            return self::$defaultCache;
        }

        if ($this->hasLaravel()) {
            return app(CacheDriver::class);
        }

        throw new RuntimeException(
            'No cache provided. Call Concurrent::useCache() or pass cache: to the constructor.'
        );
    }

    /**
     * Resolve the default lock backend. Checks global override first,
     * then falls back to Laravel's container.
     *
     * @throws RuntimeException If no lock driver is available.
     */
    private function resolveDefaultLock(): LockDriver
    {
        if (self::$defaultLock !== null) {
            return self::$defaultLock;
        }

        if ($this->hasLaravel()) {
            return app(LockDriver::class);
        }

        throw new RuntimeException(
            'No lock provided. Call Concurrent::useLock() or pass lock: to the constructor.'
        );
    }

    /**
     * Check if Laravel's application class is available.
     */
    private function hasLaravel(): bool
    {
        return class_exists(\Illuminate\Foundation\Application::class) && function_exists('app');
    }

    // ----------[ Auto-Key Resolution ]----------

    /**
     * Resolve the object that owns this instance as a property.
     * Walks the call stack to find the constructor that created this instance.
     *
     * @throws RuntimeException If no owning constructor is found.
     */
    private function resolveSource(): mixed
    {
        $source = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, self::MAX_BACKTRACE_DEPTH);

        foreach ($source as $item) {
            $object = $item['object'] ?? null;
            $function = $item['function'] ?? null;

            if ($object && $object !== $this && $function === '__construct') {
                return $object;
            }
        }

        throw new RuntimeException(
            'No key provided. Pass a key: new ' . static::class . '(key: "my-key"). '
            . 'A key can only be omitted when created inside a class constructor as a property.'
        );
    }

    /**
     * Generate a cache key from the owning class name and property name.
     * E.g. "App\Services\RateLimiter:attempts"
     *
     * @throws RuntimeException If no matching property is found.
     */
    private function resolveKeyFromSourceProperty(): string
    {
        $reflector = new ReflectionClass($this->source);

        foreach ($reflector->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (!$property->isInitialized($this->source)) {
                continue;
            }

            $value = $property->getValue($this->source);

            if ($value === $this) {
                return "{$reflector->getName()}:{$property->getName()}";
            }
        }

        throw new RuntimeException(
            'Unable to auto-resolve cache key. Pass a key: new ' . static::class . '(key: "my-key"). '
            . 'A key can only be omitted when created inside a class constructor as a property.'
        );
    }
}
