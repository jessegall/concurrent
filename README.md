# Concurrent

Thread-safe shared state for PHP. Wrap any value — objects, arrays, scalars — in a concurrent proxy that handles locking, caching, and persistence across processes automatically. Works with Laravel out of the box, or with any PHP project via pluggable cache and lock drivers.

Ships with ready-to-use data structures: `ConcurrentMap`, `ConcurrentSet`, `ConcurrentCounter`, `ConcurrentQueue`, and `ConcurrentList`.

## Built-in Data Structures

The package ships with thread-safe data structures. Pass a `key` for standalone use, or create inside a class constructor without a key to auto-generate it from the class and property name:

```php
class MyService
{
    private ConcurrentMap $cache;    // auto-key: "MyService:cache"
    private ConcurrentCounter $hits; // auto-key: "MyService:hits"

    public function __construct()
    {
        $this->cache = new ConcurrentMap;
        $this->hits = new ConcurrentCounter;
    }
}
```

### ConcurrentMap

A key-value map — like Java's `ConcurrentMap` or Go's `sync.Map`.

```php
use JesseGall\Concurrent\ConcurrentMap;

$map = new ConcurrentMap('app:settings');

$map->set('dark-mode', true);
$map->get('dark-mode');          // true
$map->get('missing', 'default'); // "default"
$map->has('dark-mode');          // true
$map->remove('dark-mode');
$map->all();                     // []
```

### ConcurrentSet

A collection of unique values — duplicates are ignored.

```php
use JesseGall\Concurrent\ConcurrentSet;

$set = new ConcurrentSet('users:online');

$set->add('alice');
$set->add('bob');
$set->add('alice');           // ignored
$set->contains('alice');      // true
$set->count();                // 2
$set->all();                  // ['alice', 'bob']
$set->remove('bob');
$set->clear();
```

### ConcurrentCounter

An atomic counter — safe increment/decrement across processes.

```php
use JesseGall\Concurrent\ConcurrentCounter;

$counter = new ConcurrentCounter('stats:visitors');

$counter->increment();
$counter->increment(5);
$counter->decrement();
$counter->count();    // 5
$counter->reset();
```

**Bounds** — optional `min`, `max`, and `wrap` constrain the counter:

```php
// Clamp: values stay inside [0, 100] on every write. Going below min
// or above max is silently pinned to the boundary.
$clamped = new ConcurrentCounter('jobs:in-flight', min: 0, max: 100);
$clamped->decrement(5);   // 0, not -5
$clamped->increment(999); // 100

// Wrap: modulo-style rollover (odometer / dice / circular index).
// Requires max; min defaults to 0 if omitted. Inclusive on both ends.
$dice = new ConcurrentCounter('die:face', min: 1, max: 6, wrap: true);
$dice->increment(5);   // 6
$dice->increment();    // 1  (wraps)
$dice->decrement(2);   // 5  (wraps backwards)

// Zero-based wrap — the common case. Min is implicit 0.
$cursor = new ConcurrentCounter('cursor', max: 9, wrap: true);
$cursor->increment(11); // 1
```

With bounds set, they also act as a read-time validator: any cached
value outside the range (from external writes, schema drift, etc.) is
treated as invalid and the counter self-heals by falling back to `min`.
`reset()` returns to `min` when set, otherwise `0`.

### ConcurrentQueue

A FIFO queue — push from one process, pop from another.

```php
use JesseGall\Concurrent\ConcurrentQueue;

$queue = new ConcurrentQueue('app:event-buffer');

$queue->push(['type' => 'order.created', 'id' => 42]);
$queue->push(['type' => 'user.registered', 'id' => 7]);

$queue->peek();     // first item (doesn't remove)
$queue->pop();      // first item (removes)
$queue->size();     // 1
$queue->isEmpty();  // false
$queue->clear();
```

### ConcurrentList

An ordered list — allows duplicates, preserves insertion order. All methods are chainable.

```php
use JesseGall\Concurrent\ConcurrentList;

$list = new ConcurrentList('app:prices');

$list->add(10.00)->add(20.00)->add(30.00);
$list->get(0);                // 10.00
$list->get(99, 'default');    // "default"
$list->count();               // 3
$list->all();                 // [10.00, 20.00, 30.00]
$list->remove(1);             // re-indexes automatically
$list->isEmpty();             // false
$list->clear();

// Iterate — return false to break early
$list->each(function (float $price) {
    if ($price > 20.00) return false;
});

// Transform — with & or return value
$list->map(fn (float $price) => $price * 1.1);

// Filter — keep items matching the predicate
$list->filter(fn (float $price) => $price > 15.00);

// Methods are chainable — each holds its own lock
$list->map(fn (float $price) => $price * 1.1)
     ->filter(fn (float $price) => $price > 15.00);

// Chain — single lock for all operations
$list->chain()
     ->map(fn (float $price) => $price * 1.1)
     ->filter(fn (float $price) => $price > 15.00)
     ->each(fn (float $price) => log($price));

// Flush — execute chain and return the value
$prices = $list->chain()
     ->map(fn (float $price) => $price * 1.1)
     ->filter(fn (float $price) => $price > 15.00)
     ->flush();
```

## Why?

When multiple processes (web requests, queue workers, cron jobs) need to share state, you end up scattering cache calls across your codebase — duplicated key strings, no locking, race conditions on read-modify-write, and business logic tangled with cache mechanics.

**Concurrent** wraps any value in a thread-safe proxy. You interact with it normally — method calls, property access, array operations — and the wrapper handles locking and persistence. Reads never lock. Writes are atomic.

## Installation

```bash
composer require jessegall/concurrent
```

## Wrapping Any Value

Turning any value into a concurrent value is as simple as wrapping it in a `Concurrent` instance. Method calls, property access, array operations — everything is proxied through the cache with locking.

```php
use JesseGall\Concurrent\Concurrent;

/** @var Concurrent<ShoppingCart> $cart */
$cart = new Concurrent(
    key: "cart:{$userId}",
    default: fn () => new ShoppingCart(),
    ttl: 1800,
);

$cart->addItem('T-Shirt', 2);  // method call (locks, writes back)
echo $cart->itemCount();       // method call (locks — see "Read-only methods" to skip)
echo count($cart->items);      // property read (no lock)
```

### Invoke for get, set, and forget

```php
$counter = new Concurrent(key: 'visitor-count', default: 0, ttl: 3600);

$counter();                              // get (no lock)
$counter(42);                            // set (locks)
$counter(fn ($current) => $current + 1); // atomic update (locks)
$counter(null);                          // forget (locks)
```

### Atomic updates — three callback styles

When you pass a closure to `$concurrent(...)`, the wrapper runs it inside a single lock and persists the result. There are three styles, all atomic:

```php
// 1. Bound $this — zero-param closure. $this is the Concurrent itself,
//    so property writes and method calls route through the proxy under
//    the same lock (re-entrant — no extra lock acquisitions).
$concurrent(function () {
    $this->count++;
    $this->status = 'processing';
    $this->bump();
});

// 2. Reference parameter — modify in place, no return needed.
$concurrent(fn (&$data) => $data->count++);
$concurrent(function (SessionData &$data) {
    $data->total = 100;
    $data->status = 'processing';
});

// 3. Return-style — receive the value, return the new one.
$concurrent(fn ($data) => $data + 1);
```

**`$concurrent->count++` outside a callback is _not_ atomic** — it's a read (`__get`, no lock) followed by a write (`__set`, locks). Another process can write in between. For safe increments, use a callback (any of the three styles above).

Similarly, nested modifications like `$concurrent->items[] = 'x'` silently fail because `__get` returns a copy. Use a callback:

```php
$concurrent(function () { $this->items[] = 'x'; });    // ✓ bound
$concurrent(fn (&$d) => $d->items[] = 'x');            // ✓ reference
```

## Read-only Methods

Method calls on a wrapped value lock + write back by default. For pure accessors, declare them read-only so they skip the lock entirely. Two ways to do it:

**Per-method attribute** — mark methods on the wrapped value's class:

```php
use JesseGall\Concurrent\Attributes\ReadonlyMethod;

class CartData
{
    public array $items = [];

    #[ReadonlyMethod]
    public function total(): int { /* pure */ }
}
```

**Class-level interface** — list method names on the Concurrent subclass:

```php
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

class Cart extends Concurrent implements DeclaresReadOnlyMethods
{
    public static function readOnlyMethods(): array
    {
        return ['total', 'itemCount'];
    }
}
```

Either way, the method skips locking and isn't written back. If a read-only method actually mutates the wrapped value, a `ReadonlyViolationException` is thrown — silently-discarded writes would otherwise be a confusing class of bug.

## Writing Your Own Concurrent Class

Extend `Concurrent` to encapsulate the key, default, TTL, and domain methods:

```php
use JesseGall\Concurrent\Attributes\ReadonlyMethod;
use JesseGall\Concurrent\Concurrent;

class SessionData
{
    public int $processed = 0;
    public int $total = 0;
    public string $status = 'pending';
    public array $errors = [];

    #[ReadonlyMethod]
    public function getProgress(): int
    {
        return $this->total > 0
            ? (int) round(($this->processed / $this->total) * 100)
            : 0;
    }
}

/**
 * @mixin SessionData
 */
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

    // Bound $this — multiple mutations under one lock.
    public function start(int $total): void
    {
        $this(function () use ($total) {
            $this->total = $total;
            $this->status = 'processing';
        });
    }

    // Reference parameter — atomic increment.
    public function advance(): void
    {
        $this(fn (SessionData &$data) => $data->processed++);
    }

    // Reference — array append.
    public function addError(string $message): void
    {
        $this(fn (SessionData &$data) => $data->errors[] = $message);
    }

    // Property proxy — simple overwrite.
    public function complete(): void
    {
        $this->status = 'completed';
    }
}

// Usage — queue job writes, controller reads, same key = same state
$session = new ProcessingSession($uploadId);

$session->start(1000);
$session->advance();
$session->addError('Row 42: Invalid email');
$session->getProgress();  // read-only, no lock
$session->status;         // "processing"
$session->complete();
```

## Helper Traits

### WithAccessors — get / set / has / update

Adds private `get`, `set`, `has`, and `update` helpers for use inside a subclass. These are intentional building blocks for domain methods, so they're private by default. Override visibility per-method when you want to expose them.

```php
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\WithAccessors;

class FeatureFlags extends Concurrent
{
    use WithAccessors;

    public function __construct() {
        parent::__construct(key: 'feature-flags', default: fn () => []);
    }

    public function enable(string $name): void
    {
        $this->set($name, true);
    }

    public function isEnabled(string $name): bool
    {
        return (bool) $this->get($name, false);
    }

    public function toggle(string $name): void
    {
        $this->update(function () use ($name) {
            $this->{$name} = ! ($this->{$name} ?? false);
        });
    }
}
```

Need them as part of the public API? Use PHP's trait conflict resolution:

```php
class KeyValueStore extends Concurrent
{
    use WithAccessors {
        get as public;
        set as public;
        has as public;
        update as public;
    }
}
```

`update(Closure $fn)` accepts any of the three callback styles (bound `$this`, reference parameter, return-style) — it just delegates to `__invoke`.

### WithPointer — track "the current" instance

Some Concurrent classes are per-run or per-session — a fresh ID minted each time, with a side pointer that remembers which one is "current." `WithPointer` packages that pattern. Subclasses implement `fromPointerId()` (since Concurrent constructors can have any shape), and get `start()` / `current()` / `release()` for free.

```php
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\WithPointer;

final class CurrentImport extends Concurrent
{
    use WithPointer;

    public function __construct(public readonly string $runId)
    {
        parent::__construct(
            key: "import:{$runId}",
            default: fn () => new ImportData,
        );
    }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        return new static($id);
    }
}

CurrentImport::start();        // mint a new run, claim the pointer
CurrentImport::current();      // resolve the pointed-to instance, or null
CurrentImport::release();      // clear the pointer
```

Constructor takes more than just the ID? Pass the extras through `start()` / `current()` and reorder them inside `fromPointerId()`:

```php
final class TenantedImport extends Concurrent
{
    use WithPointer;

    public function __construct(
        public readonly string $tenant,
        public readonly string $runId,
    ) { /* ... */ }

    protected static function fromPointerId(string $id, mixed ...$args): static
    {
        // $id = runId; $args[0] = tenant
        return new static($args[0], $id);
    }
}

TenantedImport::start('acme');     // mint a new runId for tenant "acme"
TenantedImport::current('acme');   // resolve, supplying the tenant
```

Override hooks: `pointerKey()` for a stable, class-name-independent key; `generateId()` to plug in UUIDs/ULIDs/anything.

For ad-hoc usage without the trait, use `ConcurrentPointer` directly — it's a `Concurrent<string|null>` with a factory closure.

## Using Without Laravel

Concurrent works with any PHP project. Pass your own `Cache` and `Lock` implementations:

Implement two interfaces:

```php
use JesseGall\Concurrent\Contracts\CacheDriver;
use JesseGall\Concurrent\Contracts\LockDriver;

class RedisCache implements CacheDriver
{
    public function get(string $key, mixed $default = null): mixed { /* ... */ }
    public function put(string $key, mixed $value, int $ttl): void { /* ... */ }
    public function forget(string $key): void { /* ... */ }
}

class RedisLock implements LockDriver
{
    // Must block up to $timeout seconds, then execute the callback.
    // Release the lock when the callback completes.
    public function acquire(string $key, int $ttl, int $timeout, callable $callback): mixed { /* ... */ }
}
```

Then configure them globally — all `Concurrent` instances (including built-in data structures) will use these drivers:

```php
use JesseGall\Concurrent\Concurrent;

Concurrent::useCache(new RedisCache());
Concurrent::useLock(new RedisLock());
```

Or pass them to a specific instance:

```php
$concurrent = new Concurrent(
    key: 'my-key',
    default: 0,
    cache: new RedisCache(),
    lock: new RedisLock(),
);
```

For testing, the package ships with `InMemoryCache` and `InMemoryLock`:

```php
use JesseGall\Concurrent\Testing\InMemoryCache;
use JesseGall\Concurrent\Testing\InMemoryLock;

Concurrent::useCache(new InMemoryCache());
Concurrent::useLock(new InMemoryLock());

// Reset to default resolution (e.g. in tearDown)
Concurrent::resetDrivers();
```

With Laravel, no setup needed — the service provider auto-registers the cache and lock backends.

## How It Works

**Writes lock, reads don't.** Only mutating operations acquire a distributed lock:

1. **Lock** acquired
2. Current value **read from cache**
3. Operation **executed on the value**
4. Modified value **written back to cache**
5. **Lock released**

Read operations — `$concurrent()`, `$concurrent->property`, `isset()`, and methods declared as read-only via `#[ReadonlyMethod]` or `DeclaresReadOnlyMethods` — read directly from cache without locking. This means reads never block, even when another process is writing.

Locks are **re-entrant**: nested writes inside a callback (e.g. `$this->prop = X` inside a bound `$this` closure) reuse the outer lock instead of acquiring a new one, so the entire callback runs as a single atomic operation.

### When to invoke vs property proxy

| Operation | Use | Example |
|---|---|---|
| Simple overwrite | Property proxy | `$this->status = 'done'` |
| Increment / decrement | Invoke with `&` or bound `$this` | `$this(fn (&$d) => $d->count++)` |
| Update multiple fields | Invoke with `&` or bound `$this` | `$this(function () { $this->a = 1; $this->b = 2; })` |
| Append to array | Invoke with `&` | `$this(fn (&$d) => $d->items[] = ...)` |

## Requirements

- PHP 8.4+
- Any cache backend (Redis recommended)
- Laravel 10–13 supported out of the box (optional — works without Laravel via custom `CacheDriver` and `LockDriver` implementations)

## License

MIT
