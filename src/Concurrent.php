<?php

namespace JesseGall\Concurrent;

use ArrayAccess;
use Closure;
use InvalidArgumentException;
use IteratorAggregate;
use JesseGall\Concurrent\Attributes\ReadonlyMethod;
use JesseGall\Concurrent\Contracts\CacheDriver;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;
use JesseGall\Concurrent\Contracts\LockDriver;
use JesseGall\Concurrent\Exceptions\ReadonlyViolationException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Traversable;

/**
 * Thread-safe wrapper for a cached value. Reads, writes, method calls, and
 * property access on this object are proxied to the wrapped value with
 * locking and cache persistence.
 *
 * @template TValue
 *
 * @mixin TValue
 */
class Concurrent implements ArrayAccess, IteratorAggregate
{
    use ForwardsCallsToTarget;

    private const int MAX_BACKTRACE_DEPTH = 10;

    private static CacheDriver|null $defaultCache = null;
    private static LockDriver|null $defaultLock = null;

    /** @var TValue|callable(): TValue */
    private readonly mixed $default;
    private readonly int|null $ttl;
    private readonly int $lockDuration;
    private readonly bool $readLock;
    private readonly ConcurrentValueValidator $validator;
    private readonly CacheDriver $cacheDriver;
    private readonly LockDriver $lockDriver;

    /** Re-entrancy guard so nested writes share the outer lock. */
    private bool $isLocked = false;

    private mixed $source = null;
    private bool $keyResolved = false;

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
     * @param string|null $key Cache key. When null, auto-generated from the owning class+property.
     * @param TValue|callable(): TValue $default
     * @param int|null $ttl Seconds. Null = forever.
     * @param callable(TValue): bool|null $validator
     * @param int $lockDuration Lock TTL and acquire timeout (seconds).
     * @param bool $readLock When true, reads acquire the lock too.
     */
    public function __construct(
        string|null      $key = null,
        mixed            $default = null,
        int|null         $ttl = null,
        callable|null    $validator = null,
        CacheDriver|null $cache = null,
        LockDriver|null  $lock = null,
        int              $lockDuration = 10,
        bool             $readLock = false,
    )
    {
        $this->default = $default;
        $this->ttl = $ttl;
        $this->lockDuration = $lockDuration;
        $this->readLock = $readLock;
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

    public static function useCache(CacheDriver $cache): void
    {
        self::$defaultCache = $cache;
    }

    public static function useLock(LockDriver $lock): void
    {
        self::$defaultLock = $lock;
    }

    public static function resetDrivers(): void
    {
        self::$defaultCache = null;
        self::$defaultLock = null;
    }

    // ----------[ Invoke ]----------

    /**
     * No args: get. Null: forget. Callable: atomic update. Other: store.
     *
     * @param  TValue|callable(TValue): TValue|null  $value
     * @return TValue|void
     */
    public function __invoke(mixed $value = null)
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

        $this->lock()->set($value);
    }

    // ----------[ Magic Methods ]----------

    public function __call(string $name, array $arguments)
    {
        $target = $this->get();

        if ($this->isReadOnlyMethod($name, $target)) {
            return $this->callReadOnly($target, $name, $arguments);
        }

        return $this->lock()->call($name, $arguments);
    }

    public function __get(string $name)
    {
        return $this->getProperty($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->lock()->setProperty($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->isset($name);
    }

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

    /** @return TValue */
    private function get(): mixed
    {
        $fetch = function (): mixed {
            $value = $this->cacheDriver->get($this->key, fn() => $this->resolveDefaultValue());

            if ($this->validator->invalid($value)) {
                $value = $this->resolveDefaultValue();
                $this->forget();
            }

            return $value;
        };

        return $this->readLock ? $this->lock($fetch) : $fetch();
    }

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
     * Resolve a callable value (zero-param binds $this via BoundProxy;
     * by-ref param mutates in place; otherwise return-style), then write.
     *
     * @throws InvalidArgumentException If the validator rejects the value.
     */
    private function set(mixed $value = null): void
    {
        if (is_callable($value) && !is_string($value)) {
            if ($this->shouldBindThis($value)) {
                $target = $this->get();
                $proxy = new BoundProxy($target, $this);

                $scope = (new ReflectionFunction($value))->getClosureScopeClass()?->getName();
                $bound = Closure::bind($value, $proxy, $scope);

                $result = $bound();

                if ($proxy->touchedData) {
                    $value = $target;
                } elseif ($result !== null) {
                    $value = $result;
                } else {
                    return;
                }
            } elseif ($this->acceptsByReference($value)) {
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

    private function shouldBindThis(mixed $value): bool
    {
        if (! $value instanceof Closure) {
            return false;
        }

        $reflection = new ReflectionFunction($value);

        return ! $reflection->isStatic()
            && $reflection->getNumberOfParameters() === 0;
    }

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

    private function forget(): void
    {
        $this->cacheDriver->forget($this->key);
    }

    // ----------[ Locking ]----------

    /**
     * No callback: returns a chainable proxy. With callback: runs it under
     * the lock. Re-entrant — nested calls reuse the outer lock.
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

    /** @return TValue */
    private function resolveDefaultValue(): mixed
    {
        return is_callable($this->default) ? ($this->default)() : $this->default;
    }

    private function call(string $name, array $arguments): mixed
    {
        $target = $this->get();
        $result = $this->forwardDecoratedCallTo($target, $name, $arguments);
        $this->set($target);

        return $result;
    }

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

    private function isset(string $property): bool
    {
        $target = $this->get();

        return match (true) {
            is_array($target) => isset($target[$property]),
            is_object($target) => isset($target->{$property}),
            default => false,
        };
    }

    private function isReadOnlyMethod(string $name, mixed $target): bool
    {
        if ($this instanceof DeclaresReadOnlyMethods
            && in_array($name, static::readOnlyMethods(), true)) {
            return true;
        }

        return $this->hasReadonlyAttribute($name, $target);
    }

    private function hasReadonlyAttribute(string $name, mixed $target): bool
    {
        if (! is_object($target) || ! method_exists($target, $name)) {
            return false;
        }

        $method = new ReflectionMethod($target, $name);

        return $method->getAttributes(ReadonlyMethod::class) !== [];
    }

    /**
     * Run a read-only method without locking. Throws if the method actually
     * mutates the wrapped value.
     */
    private function callReadOnly(mixed $target, string $name, array $arguments): mixed
    {
        $before = serialize($target);

        $result = $this->forwardDecoratedCallTo($target, $name, $arguments);

        if (serialize($target) !== $before) {
            $class = is_object($target) ? $target::class : gettype($target);

            throw new ReadonlyViolationException(
                "Method {$class}::{$name}() is declared read-only but mutated the wrapped value."
            );
        }

        return $result;
    }

    private function acceptsByReference(callable $callable): bool
    {
        return CallableInspector::acceptsByReference($callable);
    }

    // ----------[ Default Resolution ]----------

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

    private function hasLaravel(): bool
    {
        return class_exists(\Illuminate\Foundation\Application::class) && function_exists('app');
    }

    // ----------[ Auto-Key Resolution ]----------

    /**
     * Walk the call stack to find the constructor that created this instance.
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

    /** @throws RuntimeException If no matching property is found. */
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
