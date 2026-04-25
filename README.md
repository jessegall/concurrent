# Concurrent

Thread-safe shared state for PHP. Wrap any value — objects, arrays, scalars — in a proxy that handles locking, caching, and persistence across processes. Works with Laravel out of the box; pluggable cache and lock drivers otherwise.

## Why?

When multiple processes (web requests, queue workers, cron jobs) need to share state, you scatter cache calls across the codebase: duplicated keys, no locking, race conditions on read-modify-write, business logic tangled with cache plumbing.

**Concurrent** wraps the value in a thread-safe proxy. You interact with it normally — methods, properties, array ops — and it handles locking and persistence. Reads never lock. Writes are atomic.

## Installation

```bash
composer require jessegall/concurrent
```

## Wrapping Any Value

```php
use JesseGall\Concurrent\Concurrent;

/** @var Concurrent<ShoppingCart> $cart */
$cart = new Concurrent(
    key: "cart:{$userId}",
    default: fn () => new ShoppingCart(),
    ttl: 1800,
);

$cart->addItem('T-Shirt', 2);  // method call — locks, writes back
$cart->itemCount();            // method call — locks (see Read-only methods to skip)
$cart->items;                  // property read — no lock
$cart();                       // get the value
$cart(null);                   // forget
```

## Atomic Updates

Pass a zero-param closure to `$concurrent(...)`. `$this` is bound to the wrapped value: read properties, write properties, append to arrays, call methods — all under one lock, all atomic. **This is the recommended style.**

```php
$cart(function () {
    $this->items[] = $newItem;        // array append — works
    $this->totals['subtotal'] = 100;  // nested array write — works
    $this->status = 'pending';
    $this->recalculate();             // method on wrapped value
});
```

`$this` falls through to the Concurrent subclass for missing methods, so domain methods on your `extends Concurrent` class are reachable too. `self::`, `parent::`, and `static::` resolve to the lexical scope where the closure was defined.

Arrow functions work too — terse one-liners with bound `$this`:

```php
$counter(fn () => $this->count++);
$cart(fn () => $this->items[] = $newItem);
```

Two alternative styles, both atomic:

```php
$cart(fn (Cart &$data) => $data->items[] = $newItem); // by-reference param
$counter(fn ($n) => $n + 1);                          // return-style
```

`$concurrent->count++` *outside* a callback is **not** atomic — it's a read followed by a write, and another process can interleave. Always wrap multi-step or read-modify-write operations in a callback.

## Subclassing

Encapsulate the key, default, TTL, and domain methods:

```php
class ProcessingSession extends Concurrent
{
    public function __construct(string $id)
    {
        parent::__construct(
            key: "processing:{$id}",
            default: fn () => new SessionData(),
            ttl: 3600,
            validator: fn ($v) => $v instanceof SessionData,
        );
    }

    public function start(int $total): void
    {
        $this(function () use ($total) {
            $this->total = $total;
            $this->status = 'processing';
        });
    }

    public function advance(): void
    {
        $this(fn () => $this->processed++);
    }

    public function addError(string $message): void
    {
        $this(fn () => $this->errors[] = $message);
    }
}
```

## Helper Traits

### WithAccessors

Adds private `get`, `set`, `has`, `update`, `clear` helpers for use inside a subclass — building blocks for domain methods.

```php
class UserActivity extends Concurrent
{
    use WithAccessors;

    public function __construct(int $userId) {
        parent::__construct(
            key: "activity:{$userId}",
            default: fn () => new ActivityData,
            ttl: 86400,
        );
    }

    public function recordLogin(): void
    {
        $this->update(function () {
            $this->loginCount++;
            $this->lastLoginAt = time();
        });
    }

    public function loginCount(): int   { return $this->get('loginCount', 0); }
    public function lastLoginAt(): ?int { return $this->get('lastLoginAt'); }
    public function reset(): void       { $this->clear(); }
}
```

Private by default. Expose them per-method via PHP's trait conflict resolution: `use WithAccessors { get as public; ... }`.

### WithPointer

Tracks "the current" instance of a Concurrent class. Implement `fromPointerId()` (constructor shapes vary), get `start()` / `current()` / `release()` for free.

```php
final class CurrentImport extends Concurrent
{
    use WithPointer;

    public function __construct(public readonly string $runId) { /* ... */ }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id);
    }
}

CurrentImport::start();      // mint a new run, claim the pointer
CurrentImport::current();    // resolve the pointed-to instance, or null
CurrentImport::release();    // clear the pointer
```

Override `pointerKey()` for a stable key, `generateId()` for UUIDs/ULIDs/etc. For ad-hoc usage, `ConcurrentPointer` is the underlying primitive.

## Read-only Methods

For pure accessors, mark them read-only to skip locking. Either with `#[ReadonlyMethod]` on the wrapped value's method, or by listing names on a Concurrent subclass via `DeclaresReadOnlyMethods`. Mutating from a read-only method throws `ReadonlyViolationException` so silent write loss is caught early.

## Built-in Data Structures

The package ships with thread-safe data structures built on top of `Concurrent` — useful out of the box, also good reference implementations:

- `ConcurrentMap` — key-value map.
- `ConcurrentSet` — collection of unique values.
- `ConcurrentCounter` — atomic counter, optional `min`/`max`/`wrap`.
- `ConcurrentQueue` — FIFO queue.
- `ConcurrentList` — ordered list with chainable map/filter/each.

Each has its own short, focused API; see the source if you want the full method list.

## Using Without Laravel

Implement `CacheDriver` and `LockDriver` against your backend (Redis, etc.), then register them globally:

```php
Concurrent::useCache(new RedisCache());
Concurrent::useLock(new RedisLock());
```

Or pass them to a single instance via the constructor's `cache:` and `lock:` arguments. For tests, the package ships `InMemoryCache` and `InMemoryLock`.

With Laravel, no setup needed — the service provider auto-registers everything.

## How It Works

**Writes lock, reads don't.** A mutating operation: acquire lock → read from cache → run the operation → write back → release. Reads (`$concurrent()`, property reads, isset, read-only methods) hit the cache directly, so they never block.

Locks are **re-entrant**: nested writes inside a callback (e.g. multiple `$this->prop = X` inside a bound closure) reuse the outer lock — the entire callback is one atomic operation, one acquire/release pair.

## Requirements

- PHP 8.4+
- A cache backend (Redis recommended for production)
- Optional: Laravel 10–13 for zero-config integration

## License

MIT
