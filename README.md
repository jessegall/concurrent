# Concurrent

Thread-safe shared state for PHP. Wrap any value (object, array, scalar) in a proxy that handles locking, caching, and persistence across processes. Works with Laravel out of the box; pluggable cache and lock drivers otherwise.

## Why?

When multiple processes (web requests, queue workers, cron jobs) share state, you scatter cache calls across the codebase: duplicated keys, no locking, race conditions on read-modify-write, business logic tangled with cache plumbing.

Concurrent wraps the value in a thread-safe proxy. You interact with it normally (methods, properties, array ops); it handles locking and persistence. Reads never lock. Writes are atomic.

## Installation

```bash
composer require jessegall/concurrent
```

## Wrapping Any Value

Wrap any value by passing it (or a factory) as the `default`. The wrapper looks and acts like the value itself: methods, properties, array access all proxy through. Reads hit the cache directly; writes lock, mutate, write back.

```php
use JesseGall\Concurrent\Concurrent;

/** @var Concurrent<ShoppingCart> $cart */
$cart = new Concurrent(
    key: "cart:{$userId}",
    default: fn () => new ShoppingCart(),
    ttl: 1800,
);

$cart->addItem('T-Shirt', 2);  // method call: locks, writes back
$cart->itemCount();            // method call: locks (see Read-only methods to skip)
$cart->items;                  // property read: no lock
$cart();                       // get the value
$cart(null);                   // forget
```

## Atomic Updates

Three ways to mutate state atomically. Pick whichever fits.

### 1. Methods on the wrapped class

The cleanest option when you own the source: put the mutation logic in a method on the wrapped class.

```php
class Cart {
    public array $items = [];

    public function addItem(string $sku): void {
        $this->items[] = $sku;     // plain PHP, $this is the real Cart
        $this->lastSku = $sku;     // no proxy, no magic
    }
}

/** @var Concurrent<Cart> $cart */
$cart = new Concurrent(key: 'cart', default: fn () => new Cart);
$cart->addItem('shirt');           // atomic: Concurrent locks, runs the method, writes back
```

Inside the method body, `$this` is the actual `Cart` instance. Concurrent isn't in the picture, so PHP rules apply normally (array appends, nested writes, all of it).

### 2. Bound `$this` callbacks

For ad-hoc updates, or when the wrapped class is third-party, `\stdClass`, etc., pass a zero-param closure to `$concurrent(...)`. `$this` is bound to the wrapped value via a proxy that routes property and method access correctly:

```php
$cart(function () {
    $this->items[] = $newItem;       // array append
    $this->totals['subtotal'] = 100; // nested write
    $this->status = 'pending';
});
```

`$this` falls through to the Concurrent subclass for missing methods, so domain methods on your `extends Concurrent` class are reachable too. `self::`, `parent::`, and `static::` resolve to the lexical scope where the closure was defined.

Arrow functions work too:

```php
$counter(fn () => $this->count++);
$cart(fn () => $this->items[] = $newItem);
```

#### By-reference parameter

Take the wrapped value as a `&`-marked parameter and mutate it directly. Concurrent sees the mutated value and writes it back; no return needed.

```php
$cart(fn (Cart &$data) => $data->items[] = $newItem);

$cart(function (Cart &$data) {
    $data->items[] = $newItem;
    $data->totals['count']++;
});
```

When this is the right pick:

- The wrapped value is itself an array. Bound `$this` can't do `$this[] = X` (the proxy doesn't implement `ArrayAccess`); `&$data[] = X` works straight off the parameter.
- You want explicit PHPStan/Psalm support. A typed `Cart &$data` parameter is recognized by static analyzers; bound `$this` resolves to `BoundProxy` instead. (Generic `@extends Concurrent<Cart>` / `@var Concurrent<Cart>` annotations already cover IDE autocomplete on bound `$this`.)

The `&` is required for arrays and scalars (PHP value types). For objects it's harmless either way.

#### Return-style

Receive the value, return the new one. Best for replacing the whole value, especially scalars:

```php
$counter(fn ($n) => $n + 1);
$concurrent(fn ($value) => /* ... */);
```

### 3. A wrapper subclass that owns the domain API

When you control neither the source nor want ad-hoc callbacks all over your codebase, define your own `Concurrent` subclass with domain methods that internally use callbacks. See [Subclassing](#subclassing).

### Outside a callback

`$concurrent->count++` outside a callback or method is *not* atomic: it's a read followed by a write, and another process can interleave. `$concurrent->items[] = $x` outside a callback *silently does nothing* (PHP can't write through a by-value `__get`). Always go through one of the three patterns above.

## Subclassing

Encapsulate the key, default, TTL, and domain methods. Add `@extends Concurrent<T>` so the IDE picks up the wrapped class's methods on the subclass too. If your IDE doesn't resolve the generic and apply the `@mixin` through it, fall back to `/** @mixin T */` on the subclass:

```php
/** @extends Concurrent<SessionData> */
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

Concurrent's public surface is deliberately small. Every method on `Concurrent` is one that can't appear on the wrapped value or a subclass, so wrapping a raw value or extending `Concurrent` with your own domain methods doesn't collide with the proxy's API.

### WithAccessors

Opt-in helpers (`get`, `set`, `has`, `update`, `clear`) for subclasses that want them. Kept off the base class so they don't shadow methods on whatever you wrap.

```php
/** @extends Concurrent<ActivityData> */
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

Private by default. Expose any of them via PHP's trait conflict resolution:

```php
class Settings extends Concurrent
{
    use WithAccessors {
        get as public;
        set as public;
    }
}

$settings->set('theme', 'dark');
$settings->get('theme');
```

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

Mark pure accessors as read-only to skip locking. Either `#[ReadonlyMethod]` on the wrapped value's method, or list method names on a Concurrent subclass via `DeclaresReadOnlyMethods`. Mutating from a read-only method throws `ReadonlyViolationException` so silent write loss is caught early.

## Built-in Data Structures

Thread-safe data structures built on top of `Concurrent`:

- `ConcurrentMap` — key-value map.
- `ConcurrentSet` — collection of unique values.
- `ConcurrentCounter` — atomic counter, optional `min`/`max`/`wrap`.
- `ConcurrentQueue` — FIFO queue.
- `ConcurrentList` — ordered list with chainable map/filter/each.

Each has its own focused API; see the source for the full method list.

## Using Without Laravel

Implement `CacheDriver` and `LockDriver` against your backend (Redis, etc.) and register them globally:

```php
Concurrent::useCache(new RedisCache());
Concurrent::useLock(new RedisLock());
```

Or pass them to a single instance via the constructor's `cache:` and `lock:` arguments. For tests, the package ships `InMemoryCache` and `InMemoryLock`.

With Laravel, no setup needed: the service provider auto-registers everything.

## How It Works

Writes lock, reads don't. A mutating operation acquires the lock, reads from cache, runs the operation, writes back, releases. Reads (`$concurrent()`, property reads, isset, read-only methods) hit the cache directly and never block.

Locks are re-entrant: nested writes inside a callback (e.g. multiple `$this->prop = X` inside a bound closure) reuse the outer lock. The whole callback is one atomic operation, one acquire/release.

## Requirements

- PHP 8.4+
- A cache backend (Redis recommended for production)
- Optional: Laravel 10–13 for zero-config integration

## License

MIT
