# Concurrent

A thread-safe wrapper for cached values in Laravel. Work with shared state across processes as if it were a local object — with automatic locking, validation, and cache persistence.

## Why?

When multiple processes (web requests, queue workers, cron jobs) need to share state, you typically scatter `Cache::put()` and `Cache::get()` calls across your codebase. This leads to:

- Cache key strings duplicated everywhere
- No locking — race conditions on read-modify-write
- Business logic mixed with cache mechanics

**Concurrent** wraps any value in a thread-safe proxy. You interact with the object normally — method calls, property access, array operations — and the wrapper handles locking, serialization, and persistence automatically.

## Installation

```bash
composer require jessegall/concurrent
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

Concurrent also works with simple values — use the `__invoke()` method to get, set, or forget:

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
```

## Real-World Example: CSV Processing with Live Progress

A user uploads a 50,000-row CSV. A queue job processes it in the background. The frontend polls for progress so the user sees a live progress bar, the current step, and any row errors — all in real-time.

**Step 1: Define the shared state as a plain object**

No cache logic here — just the data and methods to manipulate it:

```php
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

class CsvProcessingSession implements DeclaresReadOnlyMethods
{
    public int $processedRows = 0;
    public int $totalRows = 0;
    public string $status = 'pending'; // pending, processing, completed, failed
    public string|null $currentStep = null;
    public array $rowErrors = [];

    public static function readOnlyMethods(): array
    {
        return ['getProgress', 'getStatus', 'getCurrentStep', 'getErrors'];
    }

    // --- Writers (called by queue job) ---

    public function start(int $totalRows): void
    {
        $this->totalRows = $totalRows;
        $this->status = 'processing';
    }

    public function advanceRow(): void
    {
        $this->processedRows++;
    }

    public function step(string $name): void
    {
        $this->currentStep = $name;
    }

    public function reportError(int $row, string $message): void
    {
        $this->rowErrors[] = "Row {$row}: {$message}";
    }

    public function complete(): void
    {
        $this->status = 'completed';
        $this->currentStep = null;
    }

    // --- Readers (called by frontend) ---

    public function getProgress(): int
    {
        if ($this->totalRows === 0) return 0;
        return (int) round(($this->processedRows / $this->totalRows) * 100);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrentStep(): string|null
    {
        return $this->currentStep;
    }

    public function getErrors(): array
    {
        return $this->rowErrors;
    }
}
```

**Step 2: Wrap it in Concurrent — same key in both processes**

```php
use JesseGall\Concurrent\Concurrent;

// This factory can live anywhere — a helper, a static method, etc.
// Any process using the same key sees the same session.
/** @return Concurrent<CsvProcessingSession> */
function csvSession(string $uploadId): Concurrent
{
    return new Concurrent(
        key: "csv-processing:{$uploadId}",
        default: fn () => new CsvProcessingSession(),
        ttl: 3600,
    );
}
```

**Step 3: Queue job writes progress**

```php
class ProcessCsvJob implements ShouldQueue
{
    public function handle(): void
    {
        $session = csvSession($this->uploadId);
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

**Step 4: Controller reads progress for the frontend**

```php
class CsvUploadController
{
    public function progress(string $uploadId): array
    {
        $session = csvSession($uploadId);

        // Reader methods — no lock, no write-back, fast
        return [
            'progress' => $session->getProgress(),    // 73
            'status'   => $session->getStatus(),      // "processing"
            'step'     => $session->getCurrentStep(),  // "Validating rows..."
            'errors'   => $session->getErrors(),       // ["Row 42: Invalid email"]
        ];
    }
}
```

The queue job and the controller run in completely different processes, but they share state through the same cache key. Writer methods lock and persist; reader methods just read — no overhead.

## Read-Only Methods

By default, every method call on a wrapped object acquires a lock, reads the value from cache, executes the method, writes the modified value back, and releases the lock. For methods that only read state, this is unnecessary overhead.

Implement `DeclaresReadOnlyMethods` to mark methods that should skip the lock and write-back:

```php
use JesseGall\Concurrent\Contracts\DeclaresReadOnlyMethods;

class Counter implements DeclaresReadOnlyMethods
{
    public int $count = 0;

    public static function readOnlyMethods(): array
    {
        return ['getCount']; // skips lock + write-back
    }

    public function increment(): void   // locks, reads, increments, writes
    {
        $this->count++;
    }

    public function getCount(): int     // just reads from cache
    {
        return $this->count;
    }
}
```

## ConcurrentClassMember

When you use `Concurrent` as a property on a class, you normally have to specify a cache key manually. `ConcurrentClassMember` removes that — it automatically generates the cache key from the owning class name and property name.

This is especially useful when a class needs to share state across processes (e.g., between a controller and a queue worker) without coordinating cache keys.

```php
use JesseGall\Concurrent\ConcurrentClassMember;

class ProductImporter
{
    /** @var ConcurrentClassMember<ImportProgress> */
    private ConcurrentClassMember $progress;

    public function __construct()
    {
        // Key is auto-generated: "ProductImporter:progress"
        $this->progress = new ConcurrentClassMember(
            default: fn () => new ImportProgress(),
            ttl: 300,
        );
    }

    public function import(array $products): void
    {
        $this->progress->total = count($products);
        $this->progress->status = 'processing';

        foreach ($products as $product) {
            $this->processProduct($product);
            $this->progress->imported++;
        }

        $this->progress->status = 'completed';
    }

    public function getProgress(): ImportProgress
    {
        // Returns the cached ImportProgress — readable by any other process
        return $this->progress();
    }
}

class ImportProgress
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
```

The generated key is deterministic — any instance of `ProductImporter` will use the key `"ProductImporter:progress"`. A queue worker writing progress and a controller reading it automatically share the same cache entry, with no manual key coordination.

## Extending Concurrent

Instead of wrapping a plain object, you can extend `Concurrent` or `ConcurrentClassMember` directly. This lets you encapsulate the default value and TTL inside the class itself — callers just provide the key:

```php
use JesseGall\Concurrent\Concurrent;

class ImportProgress extends Concurrent
{
    public function __construct(string $shopId)
    {
        parent::__construct(
            key: "import-progress:{$shopId}",
            default: fn () => ['imported' => 0, 'total' => 0, 'status' => 'pending'],
            ttl: 300,
        );
    }
}

// Clean, no configuration needed at the call site
$progress = new ImportProgress($shopId);
$progress['total'] = 500;
$progress['imported']++;
```

The same works with `ConcurrentClassMember` — extend it to bake in the defaults, and the key is still auto-generated from the owning class and property:

```php
use JesseGall\Concurrent\ConcurrentClassMember;

class Progress extends ConcurrentClassMember
{
    public function __construct()
    {
        parent::__construct(
            default: fn () => ['imported' => 0, 'total' => 0],
            ttl: 300,
        );
    }
}

class ProductImporter
{
    private Progress $progress; // key: "ProductImporter:progress"

    public function __construct()
    {
        $this->progress = new Progress();
    }
}
```

This way the `default` and `ttl` live with the class definition, not scattered across every place that creates an instance.

## Validation

Validate values before they're stored:

```php
$temperature = new Concurrent(
    key: 'sensor:temperature',
    default: 20.0,
    ttl: 300,
    validator: fn ($v) => is_float($v) && $v >= -50 && $v <= 150,
);

$temperature(22.5); // OK
$temperature(999.0); // throws InvalidArgumentException
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

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- A cache driver that supports locking (Redis recommended)

## License

MIT
