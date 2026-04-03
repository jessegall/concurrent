# Concurrent

Thread-safe shared state for PHP. Wrap any value — objects, arrays, scalars — in a concurrent proxy that handles locking, caching, and persistence across processes automatically. Works with Laravel out of the box, or with any PHP project via pluggable cache and lock drivers.

Ships with ready-to-use data structures: `ConcurrentMap`, `ConcurrentSet`, `ConcurrentCounter`, `ConcurrentQueue`, and `ConcurrentList`.

## Why?

When multiple processes (web requests, queue workers, cron jobs) need to share state, you end up scattering cache calls across your codebase — duplicated key strings, no locking, race conditions on read-modify-write, and business logic tangled with cache mechanics.

**Concurrent** wraps any value in a thread-safe proxy. You interact with it normally — method calls, property access, array operations — and the wrapper handles locking and persistence. Reads never lock. Writes are atomic.

## Installation

```bash
composer require jessegall/concurrent
```

## Quick Start

### Built-in data structures

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

#### ConcurrentMap

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

#### ConcurrentSet

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

#### ConcurrentCounter

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

#### ConcurrentQueue

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

#### ConcurrentList

An ordered list — allows duplicates, preserves insertion order. The `each()`, `map()`, and `filter()` methods hold the lock for the entire operation.

```php
use JesseGall\Concurrent\ConcurrentList;

$list = new ConcurrentList('app:prices');

$list->add(10.00);
$list->add(20.00);
$list->add(30.00);

$list->get(0);            // 10.00
$list->get(99, 'default'); // "default"
$list->count();           // 3
$list->all();             // [10.00, 20.00, 30.00]

// Remove by index (re-indexes automatically)
$list->remove(1);         // [10.00, 30.00]

// Iterate — lock held for entire loop, return false to break
$list->each(function (float $price) {
    echo $price;
    if ($price > 20.00) return false; // stop early
});

// Transform — lock held for entire operation, with & or return value
$list->map(function (float &$price) {
    $price *= 1.1;
});
$list->map(fn (float $price) => $price * 1.1);

// Filter — lock held for entire operation, keep items matching the predicate
$list->filter(fn (float $price) => $price > 15.00);

$list->isEmpty();         // false
$list->clear();
```

### Wrapping any value

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
echo $cart->itemCount();       // method call (locks, writes back — use DeclaresReadOnlyMethods to skip)
echo count($cart->items);      // property read (no lock)
```

### Invoke for get, set, and forget

```php
$counter = new Concurrent(key: 'visitor-count', default: 0, ttl: 3600);

$counter();                              // get (no lock)
$counter(42);                            // set (locks)
$counter(fn ($current) => $current + 1); // atomic update (locks)
$counter(null);                          // forget (locks)

// Reference parameters — modify directly, no return needed:
$counter(function (int &$value) {
    $value += 10;
});

```

### Reference vs return

When invoking with a callable, there are two ways to persist changes:

```php
// With & — modify in-place. The return value of the callable is ignored,
// only the modified reference is persisted:
$concurrent(fn (&$data) => $data->count++);
$concurrent(fn (&$data) => $data[] = 'item');

// Without & — the returned value becomes the new cached value:
$concurrent(fn ($data) => $data + 1);
$concurrent(function ($data) {
    $data->count++;
    return $data;
});
```

Use `&` when modifying objects or arrays in-place. Use return when computing a new value (like incrementing a scalar).

**Note:** `$concurrent->count++` is **not atomic** — it's a read (`__get`, no lock) followed by a write (`__set`, locks). Another process can write in between. For safe increments, use a reference callback:

```php
$concurrent(fn (&$data) => $data->count++); // atomic
```

Similarly, nested modifications like `$concurrent->items[] = 'x'` silently fail because `__get` returns a copy. Use a reference callback:

```php
$concurrent(fn (&$data) => $data->items[] = 'x');
```

## Writing Your Own Concurrent Class

Extend `Concurrent` to encapsulate the key, default, TTL, and domain methods. Use reference parameters (`&$data`) for atomic multi-field updates, or property proxy for simple writes:

```php
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

class SessionData
{
    public int $processed = 0;
    public int $total = 0;
    public string $status = 'pending';
    public array $errors = [];

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
class ProcessingSession extends Concurrent implements DeclaresReadOnlyMethods
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

    // Read-only methods skip locking (optional optimization)
    public static function readOnlyMethods(): array
    {
        return ['getProgress'];
    }

    // Reference parameter — atomic multi-field update
    public function start(int $total): void
    {
        $this(function (SessionData &$data) use ($total) {
            $data->total = $total;
            $data->status = 'processing';
        });
    }

    // Invoke with & — atomic increment
    public function advance(): void
    {
        $this(fn (SessionData &$data) => $data->processed++);
    }

    // Reference — array append
    public function addError(string $message): void
    {
        $this(fn (SessionData &$data) => $data->errors[] = $message);
    }

    // Property proxy — simple overwrite
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
$session->getProgress();  // 0 (read-only, no lock)
$session->status;         // "processing"
$session->complete();
```

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

Read operations — `$concurrent()`, `$concurrent->property`, `isset()`, and methods declared as read-only via `DeclaresReadOnlyMethods` — read directly from cache without locking. This means reads never block, even when another process is writing.

### When to invoke vs property proxy

| Operation | Use | Example |
|---|---|---|
| Simple overwrite | Property proxy | `$this->status = 'done'` |
| Increment / decrement | Invoke with `&` | `$this(fn (&$d) => $d->count++)` |
| Update multiple fields | Invoke with `&` | `$this(fn (&$d) => ...)` |
| Append to array | Invoke with `&` | `$this(fn (&$d) => $d->items[] = ...)` |

## Requirements

- PHP 8.2+
- Any cache backend (Redis recommended)
- Laravel 10–13 supported out of the box (optional — works without Laravel via custom `CacheDriver` and `LockDriver` implementations)

## License

MIT
