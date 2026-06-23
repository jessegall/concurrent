<?php

namespace JesseGall\Concurrent\Tests;

use Illuminate\Support\Facades\Cache;
use JesseGall\Concurrent\Exceptions\RestrictedCacheException;
use JesseGall\Concurrent\Laravel\LaravelCache;

class RestrictedCacheTest extends TestCase
{
    public function test_throws_actionable_exception_on_incomplete_class(): void
    {
        // What a restrictive store produces: unserialize() with allowed_classes => false
        // turns any object into a __PHP_Incomplete_Class.
        $incomplete = unserialize('O:3:"Box":0:{}', ['allowed_classes' => false]);

        Cache::put('test:restricted', $incomplete, 60);

        $cache = new LaravelCache;

        $this->expectException(RestrictedCacheException::class);
        $this->expectExceptionMessage("returned an incomplete object for class [Box]");
        $this->expectExceptionMessage("cache.serializable_classes");

        $cache->get('test:restricted');
    }

    public function test_passes_through_complete_values(): void
    {
        $cache = new LaravelCache;

        $cache->put('test:complete', ['ok' => true], 60);

        $this->assertSame(['ok' => true], $cache->get('test:complete'));
    }
}
