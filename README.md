# Concurrent

Thread-safe shared state for PHP. Wrap any value (object, array, scalar) in a proxy that handles locking, caching, and persistence across processes. Works with Laravel out of the box; pluggable cache and lock drivers otherwise.

## Why?

When multiple processes (web requests, queue workers, cron jobs) share state, you scatter cache calls across the codebase: duplicated keys, no locking, race conditions on read-modify-write, business logic tangled with cache plumbing.

Concurrent wraps the value in a thread-safe proxy. You interact with it normally; it handles locking and persistence. Writes are atomic.

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
$cart->couponCode = 'SAVE10';  // property write: locks, writes back
$cart();                       // get the value
$cart(null);                   // forget
```

Every write persists to the cache automatically. No `save()` or `flush()` step. Each write is atomic on its own.

## Grouping Writes Into One Atomic Update

Use a callback when you need several writes (or a read-then-write) to land as one atomic step, so nothing else can interleave.

```php
// Two separate atomic writes. Another worker can read or write
// between them and see a half-updated cart.
$cart->discount = 10;
$cart->total = $cart->subtotal - 10;

// One atomic update. The lock is held across both lines.
$cart(function (Cart $data) {
    $data->discount = 10;
    $data->total = $data->subtotal - 10;
    return $data;
});
```

Three ways to express the grouped update. Pick whichever fits.

### 1. Methods on the wrapped class

The cleanest option when you own the source: put the mutation logic in a method on the wrapped class.

```php
class Cart {
    public array $items = [];

    public function addItem(string $sku): void {
        $this->items[] = $sku;     
        $this->lastSku = $sku;    
    }
}

/** @var Concurrent<Cart> $cart */
$cart = new Concurrent(key: 'cart', default: fn () => new Cart);
$cart->addItem('shirt');           // atomic: Concurrent locks, runs the method, writes back
```

### 2. Callbacks

#### Bound Callback

Pass a zero-param closure to `$concurrent(...)`:

```php
$cart(function () {
    $this->items[] = $newItem;
    $this->totals['subtotal'] = 100;
    $this->status = 'pending';
});
```

Inside the callback, `$this` behaves like the Concurrent wrapper merged with the wrapped value: the wrapped value's properties and methods take precedence, anything missing falls through to the wrapper. `self::`, `parent::`, and `static::` still resolve to the wrapper class, so constants and static methods on it work as you'd expect.

Arrow functions work too:

```php
$counter(fn () => $this->count++);
$cart(fn () => $this->items[] = $newItem);
```

#### Transform Callback

Receive the value, return the new one. Best for replacing the whole value, especially scalars:

```php
// Arrow functions return the expression's value implicitly.
$counter(fn (int $n) => $n + 1);
$concurrent(fn (array $value) => [...$value, 'new entry']);

// Non-arrow functions need an explicit return.
$cart(function (Cart $data) {
    $data->discount = 10;
    return $data;
});
```

#### By-reference Callback

Take the wrapped value as a `&`-marked parameter and mutate it directly. Concurrent sees the mutated value and writes it back; no return needed.

```php
$cart(fn (Cart &$data) => $data->items[] = $newItem);

$cart(function (Cart &$data) {
    $data->items[] = $newItem;
    $data->totals['count']++;
});
```

Use a By-reference Callback when:

- **The wrapped value is an array.** `$this[]` doesn't work on the bound proxy; `$data[]` does.
- **You want better static analysis.** PHPStan and Psalm read a typed `Cart &$data` parameter directly. With bound `$this` they see `BoundProxy`.
- **You want the outer `$this`.** Any callback with a parameter keeps `$this` as the surrounding class, so you can still call its methods or read its properties.

Without the `&`, the closure falls back to a Transform Callback (above). A block with no `return` writes `null` to the cache. An arrow writes the expression value, so `fn ($d) => $d->items[] = $x` writes `$x`, not the cart. Use `&`, or return the value yourself.

### 3. A wrapper subclass that owns the domain API

When you control neither the source nor want ad-hoc callbacks all over your codebase, define your own `Concurrent` subclass with domain methods that internally use callbacks. See [Subclassing](#subclassing).

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

## Auto-generated Keys

When a `Concurrent` is constructed inside another class's `__construct` and stored on a property of that class, you can omit `key:`. The wrapper figures out the key on first use by reflecting on the owning class and finding the property it's assigned to. The result is `{FullyQualifiedClassName}:{propertyName}`.

```php
class RateLimiter
{
    /** @var Concurrent<array> */
    private Concurrent $attempts; // <-- Will receive auto-generated key "App\RateLimiter:attempts"

    public function __construct()
    {
        $this->attempts = new Concurrent(default: fn () => []);
    }
}
```

Two `RateLimiter` instances share the same auto-key, so they see the same cached state. 

### WithAccessors

Concurrent's public surface is deliberately small. Every method on `Concurrent` is one that can't appear on the wrapped value or a subclass, so wrapping a raw value or extending `Concurrent` with your own domain methods doesn't collide with the proxy's API.

`WithAccessors` is opt-in for that reason: it adds helpers (`get`, `set`, `has`, `update`, `clear`) on subclasses that want them, without baking them into the base class where they'd shadow methods on whatever you wrap.

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

- `ConcurrentMap`: key-value map.
- `ConcurrentSet`: collection of unique values.
- `ConcurrentCounter`: atomic counter, optional `min`/`max`/`wrap`.
- `ConcurrentQueue`: FIFO queue.
- `ConcurrentList`: ordered list with chainable map/filter/each.

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

## Caveats

A plain overwrite like `$concurrent->value = 10` is its own atomic write, no callback needed. Two shapes look like single writes but aren't:

- `$concurrent->count++` is *not* atomic. `++` is really three steps: read the value, add one, write it back. Each step locks, but nothing holds a lock across all three. If two workers both run `count++` on a value of `5`, both read `5` before either writes, both compute `6`, both write `6`. One increment is lost. Wrap it in a callback so the read and write share one lock.
- `$concurrent->items[] = $x` *silently does nothing*. PHP fetches `items` by value (a copy), appends to the copy, throws the copy away. The cache never sees the change. Wrap it in a callback to mutate the real array.

For read-modify-write or nested mutations, use a callback.

## Requirements

- PHP 8.4+
- A cache backend (Redis recommended for production)
- Optional: Laravel 10–13 for zero-config integration

## License

MIT
