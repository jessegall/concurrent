# Concurrent

Thread-safe shared state for Laravel. Wrap any value — objects, arrays, scalars — in a concurrent proxy that handles locking, caching, and persistence across processes automatically.

Ships with ready-to-use data structures: `ConcurrentMap`, `ConcurrentSet`, `ConcurrentCounter`, and `ConcurrentQueue`.

## Why?

When multiple processes (web requests, queue workers, cron jobs) need to share state, you typically scatter `Cache::put()` and `Cache::get()` calls across your codebase. This leads to:

- Cache key strings duplicated everywhere
- No locking — race conditions on read-modify-write
- Business logic mixed with cache mechanics

**Concurrent** solves this. Wrap any value in a thread-safe proxy and interact with it normally — method calls, property access, array operations — the wrapper handles the rest. Or use the built-in data structures that work out of the box.

## Table of Contents

- [Installation](#installation)
- [Built-in Data Structures](#built-in-data-structures)
- [Quick Start](#quick-start)
- [Building a Custom Concurrent Class](#building-a-custom-concurrent-class)
- [Auto-Key Resolution](#auto-key-resolution)
- [Extending Concurrent](#extending-concurrent)
- [ConcurrentApi Trait](#concurrentapi-trait)
- [How It Works](#how-it-works)
- [Read-Only Methods](#read-only-methods)
- [Validation](#validation)
- [Caveats](#caveats)
- [Requirements](#requirements)

## Installation

```bash
composer require jessegall/concurrent
```

## Built-in Data Structures

The package ships with thread-safe versions of common data structures. Each is backed by cache and safe to use across processes.

Every built-in type accepts an optional `key`. When provided, it's used as the cache key. When omitted, the key is [auto-generated](#auto-key-resolution) from the owning class and property name.

### ConcurrentMap

A key-value map — like Java's `ConcurrentMap` or Go's `sync.Map`.

```php
use JesseGall\Concurrent\ConcurrentMap;

class FeatureManager
{
    private ConcurrentMap $flags; // auto-key: "FeatureManager:flags"

    public function __construct()
    {
        $this->flags = new ConcurrentMap;
    }

    // ...
}

// Or with an explicit key — works anywhere:
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

class PresenceTracker
{
    private ConcurrentSet $online; // auto-key: "PresenceTracker:online"

    public function __construct()
    {
        $this->online = new ConcurrentSet;
    }

    // ...
}

// Or with an explicit key:
$set = new ConcurrentSet('users:online');

$set->add('alice');
$set->add('bob');
$set->add('alice');           // ignored — already in set
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

class HitCounter
{
    private ConcurrentCounter $hits; // auto-key: "HitCounter:hits"

    public function __construct()
    {
        $this->hits = new ConcurrentCounter;
    }

    // ...
}

// Or with an explicit key:
$counter = new ConcurrentCounter('stats:visitors');

$counter->increment();
$counter->increment(5);
$counter->decrement();
$counter->count();    // 5
$counter->reset();
```

### ConcurrentQueue

A FIFO queue — push from one process, pop from another.

```php
use JesseGall\Concurrent\ConcurrentQueue;

class EventBuffer
{
    private ConcurrentQueue $events; // auto-key: "EventBuffer:events"

    public function __construct()
    {
        $this->events = new ConcurrentQueue;
    }

    // ...
}

// Or with an explicit key:
$queue = new ConcurrentQueue('app:event-buffer');

$queue->push(['type' => 'order.created', 'id' => 42]);
$queue->push(['type' => 'user.registered', 'id' => 7]);

$queue->peek();     // ['type' => 'order.created', ...] (doesn't remove)
$queue->pop();      // ['type' => 'order.created', ...] (removes)
$queue->size();     // 1
$queue->isEmpty();  // false
$queue->clear();
```

## Quick Start

### Wrapping an object

Wrap any object and interact with it naturally — method calls, property access, everything is proxied through the cache with locking.

The key insight: **the same cache key in different processes points to the same value.** A queue worker can modify the object, and a web request reading the same key will see the changes immediately.

```php
use JesseGall\Concurrent\Concurrent;

class ShoppingCart
{
    public array $items = [];
    
    public function addItem(string $product, int $quantity): void
    {
        $this->items[] = ['product' => $product, 'quantity' => $quantity];
    }
    
    public function itemCount(): int
    {
        return count($this->items);
    }
}

/** @var Concurrent<ShoppingCart> $cart */
$cart = new Concurrent(
    key: "cart:{$userId}",
    default: fn () => new ShoppingCart(),
    ttl: 1800,
);

// Process A (e.g., a web request):
$cart->addItem('T-Shirt', 2);

// Process B (e.g., another request, a queue job, or a CLI command):
$cart->addItem('Hoodie', 1);
echo $cart->itemCount(); // 2 — both items are here

// Property access works too
echo count($cart->items); // 2
```

Process B sees the T-Shirt that Process A added because they use the same cache key. The value lives in cache (Redis), not in memory — so any process, request, or job that creates a `Concurrent` with `key: "cart:{$userId}"` is working on the same object.

### Scalar values and arrays

Concurrent also works with simple values — invoke the instance directly to get, set, or forget:

```php
$counter = new Concurrent(key: 'visitor-count', default: 0, ttl: 3600);

$counter();                          // get: 0
$counter(42);                        // set: 42
$counter(fn ($current) => $current + 1); // atomic update: 43
$counter(null);                      // forget

// Array access
$settings = new Concurrent(key: 'app-settings', default: fn () => [], ttl: 7200);

$settings['theme'] = 'dark';
$settings['locale'] = 'en';
isset($settings['theme']); // true

foreach ($settings as $key => $value) {
    echo "{$key}: {$value}\n"; // theme: dark, locale: en
}
```

## Building a Custom Concurrent Class

A user uploads a 50,000-row CSV. A queue job processes it in the background. The frontend polls for progress so the user sees a live progress bar, the current step, and any row errors — all in real-time.

**Step 1: Define the session as a Concurrent subclass**

The session extends `Concurrent` and implements `DeclaresReadOnlyMethods`. The data object holds the state, and the session provides writer methods that invoke the instance for atomic updates. Reader methods are proxied to the data object and skip locking:

```php
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

/**
 * @mixin CsvProcessingData
 */
class CsvProcessingSession extends Concurrent implements DeclaresReadOnlyMethods
{
    public function __construct(string $uploadId)
    {
        parent::__construct(
            key: "csv-processing:{$uploadId}",
            default: fn () => new CsvProcessingData(),
            ttl: 3600,
            validator: fn ($value) => $value instanceof CsvProcessingData, // rejects stale/corrupt cache entries
        );
    }

    // Optional optimization: these methods only read state, so they skip
    // the lock and don't write back to cache. Without this, they'd still
    // work — the overhead is minimal, but avoidable.
    public static function readOnlyMethods(): array
    {
        return ['getProgress', 'getStatus', 'getCurrentStep', 'getErrors'];
    }

    // --- Writers (called by queue job) ---

    // Invoke with a callback when you need to update multiple fields
    // atomically — the callback receives the current data, and the returned
    // value is written back in a single lock cycle.
    public function start(int $totalRows): void
    {
        $this(function (CsvProcessingData $data) use ($totalRows) {
            $data->totalRows = $totalRows;
            $data->status = 'processing';

            return $data;
        });
    }

    // For single-field increments, property proxy works fine —
    // PHP translates ++ into __get (read) + __set (write), both locked.
    public function advanceRow(): void
    {
        $this->processedRows++;
    }

    // For simple overwrites that don't depend on the current value,
    // property proxy via __set is fine — no need for a callback.
    public function step(string $name): void
    {
        $this->currentStep = $name;
    }

    // Appending to an array is a read-modify-write — invoke with a callback.
    public function reportError(int $row, string $message): void
    {
        $this(function (CsvProcessingData $data) use ($row, $message) {
            $data->rowErrors[] = "Row {$row}: {$message}";

            return $data;
        });
    }

    // Multiple fields + clearing a value = invoke with a callback for atomicity.
    public function complete(): void
    {
        $this(function (CsvProcessingData $data) {
            $data->status = 'completed';
            $data->currentStep = null;

            return $data;
        });
    }
}

class CsvProcessingData
{
    public int $processedRows = 0;
    public int $totalRows = 0;
    public string $status = 'pending';
    public string|null $currentStep = null;
    public array $rowErrors = [];

    // Reader methods — proxied through __call, declared read-only above
    public function getProgress(): int
    {
        return $this->totalRows > 0
            ? (int) round(($this->processedRows / $this->totalRows) * 100)
            : 0;
    }

    public function getStatus(): string       { return $this->status; }
    public function getCurrentStep(): ?string { return $this->currentStep; }
    public function getErrors(): array        { return $this->rowErrors; }
}
```

**Step 2: Queue job writes progress**

```php
class ProcessCsvJob implements ShouldQueue
{
    public function handle(): void
    {
        $session = new CsvProcessingSession($this->uploadId);
        $rows = $this->parseRows();

        $session->start(count($rows));
        $session->step('Validating rows...');

        foreach ($rows as $i => $row) {
            try {
                $this->processRow($row);
            } catch (ValidationException $e) {
                $session->reportError($i + 1, $e->getMessage());
            }

            $session->advanceRow();
        }

        $session->complete();
    }
}
```

**Step 3: Controller reads progress for the frontend**

```php
class CsvUploadController
{
    public function progress(string $uploadId): array
    {
        $session = new CsvProcessingSession($uploadId);

        return [
            'progress' => $session->getProgress(),    // 73 (proxied through CsvProcessingData::getProgress)
            'status'   => $session->getStatus(),      // "processing"
            'step'     => $session->getCurrentStep(),  // "Validating rows..."
            'errors'   => $session->getErrors(),       // ["Row 42: Invalid email"]
        ];
    }
}
```

The queue job and the controller create their own `CsvProcessingSession` instances — but since they use the same upload ID, they share the same cache key and therefore the same state. Writer methods lock and persist; reader methods just read — no overhead.

## Auto-Key Resolution

When no key is provided, `Concurrent` automatically generates a cache key from the owning class and property name. This is useful when you want concurrent class properties without manually coordinating keys:

```php
use JesseGall\Concurrent\ConcurrentMap;

class RateLimiter
{
    // Key is auto-generated: "RateLimiter:attempts"
    private ConcurrentMap $attempts;

    public function __construct()
    {
        $this->attempts = new ConcurrentMap();
    }

    public function hit(string $ip): void
    {
        $this->attempts->set($ip, $this->attempts->get($ip, 0) + 1);
    }

    public function isLimited(string $ip): bool
    {
        return $this->attempts->get($ip, 0) >= 10;
    }
}
```

The key `"RateLimiter:attempts"` is deterministic — any instance of `RateLimiter` shares the same cache entry. This works with all `Concurrent` subclasses, including the built-in data structures.

**Important:** Auto-key resolution only works inside a class constructor. Creating a `Concurrent` without a key in a regular method or at the top level will throw a `RuntimeException`.

## Extending Concurrent

You can extend `Concurrent` directly to encapsulate the default value, TTL, and domain-specific methods. You can also add your own methods that operate on the wrapped value — invoking with a callback for atomic multi-field updates, or property proxying for simple writes:

```php
use JesseGall\Concurrent\Concurrent;

class ImportProgressData
{
    public int $imported = 0;
    public int $total = 0;
    public string $status = 'pending';

    public function percentage(): int
    {
        return $this->total > 0
            ? (int) round(($this->imported / $this->total) * 100)
            : 0;
    }
}

/**
 * @mixin ImportProgressData
 */
class ImportProgress extends Concurrent
{
    public function __construct(string $shopId)
    {
        parent::__construct(
            key: "import-progress:{$shopId}",
            default: fn () => new ImportProgressData(),
            ttl: 300,
        );
    }

    // Invoke with a callback for atomic updates that touch multiple fields
    public function start(int $total): void
    {
        $this(function (ImportProgressData $data) use ($total) {
            $data->total = $total;
            $data->status = 'processing';

            return $data;
        });
    }

    // Single-field increments work through property proxy —
    // PHP translates ++ into __get + __set, both locked.
    public function advance(): void
    {
        $this->imported++;
    }

    // Simple overwrites work through property proxy too.
    public function complete(): void
    {
        $this->status = 'completed';
    }
}

// Clean — no configuration needed at the call site
$progress = new ImportProgress($shopId);

$progress->start(10);
$progress->advance();

// Methods on the wrapped object are proxied through __call
echo $progress->percentage(); // 10
echo $progress->imported;     // 1

$progress->complete();
echo $progress->status;       // "completed"
```

When used as a class property without a key, the cache key is auto-generated (see [Auto-Key Resolution](#auto-key-resolution)). This way the `default`, `ttl`, and domain methods live with the class definition, not scattered across every place that creates an instance.

## Read-Only Methods

By default, every method call on a wrapped object acquires a lock, reads the value from cache, executes the method, writes the modified value back, and releases the lock. For methods that only read state, this is unnecessary overhead.

Implement `DeclaresReadOnlyMethods` on the `Concurrent` subclass to mark methods that should skip the lock and write-back:

```php
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

class CounterData
{
    public int $count = 0;

    public function increment(): void { $this->count++; }
    public function getCount(): int   { return $this->count; }
}

class Counter extends Concurrent implements DeclaresReadOnlyMethods
{
    public function __construct(string $key)
    {
        parent::__construct(key: $key, default: fn () => new CounterData, ttl: 3600);
    }

    public static function readOnlyMethods(): array
    {
        return ['getCount']; // skips lock + write-back
    }
}

$counter = new Counter('my-counter');
$counter->increment(); // locks, reads, increments, writes
$counter->getCount();  // just reads from cache — no lock
```

## Validation

The validator runs in two places:

- **On write** — setting an invalid value throws `InvalidArgumentException`
- **On read** — if the cached value fails validation (e.g., corrupted or from an old code version), it's silently discarded and the default is returned instead

```php
$temperature = new Concurrent(
    key: 'sensor:temperature',
    default: 20.0,
    ttl: 300,
    validator: fn ($v) => is_float($v) && $v >= -50 && $v <= 150,
);

$temperature(22.5); // OK
$temperature(999.0); // throws InvalidArgumentException

// If something else writes garbage to the same cache key,
// reading it returns the default (20.0) instead of crashing.
```

## ConcurrentApi Trait

You might wonder why methods like `get()`, `set()`, and `forget()` aren't directly on the `Concurrent` class. The reason: `Concurrent` proxies all method calls to the wrapped object via `__call()`. If `Concurrent` had a `get()` method, and your wrapped object also had a `get()` method, you'd never be able to reach the wrapped object's version — `Concurrent`'s own method would win.

By keeping these helpers in an opt-in trait, the base `Concurrent` class has zero method name collisions with your wrapped objects. You only pull in `ConcurrentApi` on subclasses where you know the wrapped value won't have conflicting method names (e.g., when wrapping arrays or simple scalars):

```php
use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Concerns\ConcurrentApi;

class TaskQueue extends Concurrent
{
    use ConcurrentApi;
}

$queue = new TaskQueue(key: 'task-queue', default: fn () => [], ttl: 3600);

$queue->set(['task1', 'task2']);
$queue->get();                        // ['task1', 'task2']
$queue->update(fn (&$v) => $v[] = 'task3'); // atomic append
$queue->pull();                       // get + forget
$queue->forget();                     // clear
$queue->withLock(fn ($v) => /* ... */); // explicit lock
```

## How It Works

1. **Every mutating operation** (method call, property set, array write) acquires a distributed lock via `Cache::lock()`
2. The current value is **read from cache** (or the default is used)
3. The operation is **executed on the value**
4. The modified value is **written back to cache**
5. The lock is **released**

This ensures that concurrent processes never overwrite each other's changes. The lock uses Redis/database-backed distributed locking with automatic timeout.

### When to invoke vs property proxy

| Operation | Use | Example |
|---|---|---|
| Simple overwrite | Property proxy | `$this->status = 'done'` |
| Increment / decrement | Property proxy | `$this->count++` |
| Update multiple fields atomically | Invoke with callback | `$this(fn ($d) => ...)` |
| Append to array property | Invoke with callback | `$this(fn ($d) => $d->items[] = ...)` |
| Read-modify-write with logic | Invoke with callback | `$this(fn ($d) => ...)` |

## Caveats

Modifying nested properties through `__get` doesn't work as expected. This is a PHP limitation, not a Concurrent bug — `__get` returns a copy, so changes to it are lost.

```php
// These silently do nothing — the changes are lost:
$concurrent->items[] = 'new';           // appending to a nested array
$concurrent->skipped['key'] = 'value';  // setting a key on a nested array
$concurrent->address->city = 'Berlin';  // modifying a nested object property

// Use invoke with a callback instead:
$concurrent(function ($data) {
    $data->items[] = 'new';
    $data->skipped['key'] = 'value';

    return $data;
});
```

Some of these may throw an `Indirect modification` notice, but others fail silently — so always use a callback for nested modifications.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12, or 13
- A cache driver that supports locking (Redis recommended)

## License

MIT
