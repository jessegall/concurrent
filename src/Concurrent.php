<?php

namespace JesseGall\Concurrent;

use JesseGall\Concurrent\Concerns\ConcurrentApi;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;
use ArrayAccess;
use ArrayIterator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Traits\ForwardsCalls;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * A thread-safe wrapper for cached values.
 *
 * This class lets you work with cached values as if they were the wrapped
 * value itself, while automatically handling caching, validation, and locking
 * when accessing or modifying the value.
 *
 * External cache manipulation is handled exclusively through the __invoke() method to avoid
 * potential naming conflicts with methods on the cached value itself.
 *
 * Subclasses can use the `ConcurrentApi` trait to add additional helper methods
 * for getting and setting values.
 *
 * @template TValue
 *
 * @mixin TValue
 *
 * @see ConcurrentApi For additional helper methods
 */
class Concurrent implements ArrayAccess, IteratorAggregate
{
    use ForwardsCalls;

    protected const int LOCK_DURATION = 10; // seconds

    private bool $isLocked = false;

    /**
     * The cached value.
     *
     * @var TValue
     */
    protected mixed $value {
        get => $this->get();
        set {
            $this->set($value);
        }
    }

    protected readonly ConcurrentValueValidator $validator;

    /**
     * @param  string|array<string>|null  $tags
     */
    public function __construct(
        protected(set) string $key,
        public readonly mixed $default = null,
        public readonly int $ttl = 300,
        callable|null $validator = null,
        public readonly string|array|null $tags = null,
    ) {
        $this->validator = new ConcurrentValueValidator($validator);
    }

    // ----------[ ConcurrentValue ]----------

    /**
     * Acquire a distributed lock for thread-safe operations on the cached value.
     *
     * This method provides two usage patterns:
     * 1. Direct execution: Pass a callback to execute immediately within the lock
     * 2. Chained operations: Call without arguments to get a proxy for method chaining
     *
     * The lock prevents race conditions when multiple processes attempt to read/write
     * the same cached value simultaneously. Uses Redis/database-backed distributed
     * locking with automatic timeout and cleanup.
     *
     * @return HigherOrderConcurrentLockProxy|mixed
     */
    private function lock(callable|null $callback = null): mixed
    {
        if (is_null($callback))
        {
            return new HigherOrderConcurrentLockProxy($this, fn (callable $callback) => $this->lock($callback));
        }

        if ($this->isLocked)
        {
            return $callback();
        }

        $lock = Cache::lock("$this->key:lock", self::LOCK_DURATION);

        try
        {
            $this->isLocked = true;
            $lock->block(self::LOCK_DURATION / 2);

            return $callback();
        }
        finally
        {
            $lock->release();
            $this->isLocked = false;
        }
    }

    /**
     * Get, set, or forget the cached value
     *
     * This method provides three distinct behaviors based on the arguments provided:
     * 1. No arguments: Returns the current cached value (or default if cache miss)
     * 2. Null argument: Clears the cached value from storage
     * 3. Read argument: Executes the provided callable with the current cached value
     * 4. Value argument: Updates the cached value with the provided value
     *
     * When setting a value, if the value is a callable, it will be executed with the current cached value
     * as an argument, and the result of that callable will be used as the new cached value.
     *
     * @param  TValue|callable(TValue): TValue|null  $value
     * @param  bool  $read  Whether to read the cached value using the provided callable
     * @return TValue|void
     */
    public function __invoke(mixed $value = null, bool $read = false)
    {
        // -- Get cached value --
        if (func_num_args() === 0)
        {
            return $this->lock()->get();
        }

        // -- Read cached value --
        if ($read)
        {
            return $this->lock(fn () => $value($this->get()));
        }

        // -- Forget cached value --
        if (is_null($value))
        {
            $this->lock()->forget();

            return;
        }

        // -- Set cached value --
        $this->lock()->set($value);
    }

    // ----------[ Magic Methods ]----------

    public function __call(string $name, array $arguments)
    {
        if ($this->isReadOnlyMethod($name))
        {
            return $this->readOnly($name, $arguments);
        }

        return $this->lock()->call($name, $arguments);
    }

    public function __get(string $name)
    {
        return $this->lock()->getProperty($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->lock()->setProperty($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->lock()->isset($name);
    }

    public function __unset(string $name): void
    {
        $this->lock()->unset($name);
    }

    // ----------[ ArrayAccess ]----------

    public function offsetExists(mixed $offset): bool
    {
        return $this->lock()->isset($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->lock()->getProperty($offset);
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
        return $this->lock(function () {
            $value = $this->get();

            return match (true)
            {
                $value instanceof Traversable => $value,
                is_array($value) => new ArrayIterator($value),
                default => throw new InvalidArgumentException('Cached value is not iterable.'),
            };
        });
    }

    // ----------[ Repository ]----------

    protected function repository(): Repository
    {
        if ($this->tags)
        {
            return Cache::tags($this->tags);
        }

        return Cache::store();
    }

    private function get(): mixed
    {
        $value = $this->repository()->get($this->key, fn () => $this->resolveDefaultValue());

        if ($this->validator->invalid($value))
        {
            $value = $this->resolveDefaultValue();
            $this->forget();
        }

        return $value;
    }

    private function getProperty(string $key): mixed
    {
        $target = $this->get();

        return match (true)
        {
            is_array($target) => $target[$key] ?? null,
            is_object($target) => $target->{$key} ?? null,
            default => null,
        };
    }

    private function set(mixed $value = null): void
    {
        if (is_callable($value) && ! is_string($value))
        {
            $value = $value($this->get());
        }

        if ($this->validator->invalid($value))
        {
            throw new InvalidArgumentException('Invalid value provided for ConcurrentValue.');
        }

        $this->repository()->put($this->key, $value, $this->ttl);
    }

    private function setProperty(string|null $key, mixed $value): void
    {
        $target = $this->get();

        if (is_array($target))
        {
            if (is_null($key))
            {
                $target[] = $value;
            }
            else
            {
                $target[$key] = $value;
            }
        }
        elseif (is_object($target))
        {
            $target->{$key} = $value;
        }

        $this->set($target);
    }

    private function forget(): void
    {
        $this->repository()->forget($this->key);
    }

    // ----------[ Helpers ]----------

    private function resolveDefaultValue(): mixed
    {
        return value($this->default);
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

        if (is_array($target))
        {
            unset($target[$property]);
        }
        elseif (is_object($target))
        {
            unset($target->{$property});
        }

        $this->set($target);
    }

    private function isset(string $property): bool
    {
        $target = $this->get();

        return match (true)
        {
            is_array($target) => isset($target[$property]),
            is_object($target) => isset($target->{$property}),
            default => false,
        };
    }

    /**
     * Call a read-only method without locking or writing back.
     */
    private function readOnly(string $name, array $arguments): mixed
    {
        $target = $this->get();

        return $this->forwardDecoratedCallTo($target, $name, $arguments);
    }

    /**
     * Check if the method is declared as read-only by the cached value.
     */
    private function isReadOnlyMethod(string $name): bool
    {
        $target = $this->get();

        return $target instanceof DeclaresReadOnlyMethods
            && in_array($name, $target::readOnlyMethods(), true);
    }
}
