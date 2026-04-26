<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\AutoKeyResolver;
use RuntimeException;
use stdClass;

class AutoKeyResolverTest extends TestCase
{
    public function test_throws_when_not_inside_a_constructor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No key provided');

        new AutoKeyResolver(new stdClass);
    }

    public function test_throws_when_owner_is_not_a_property_of_the_source(): void
    {
        $orphan = new AutoKeyResolverOrphanHost;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to auto-resolve cache key');

        $orphan->resolver->resolve();
    }

    public function test_resolves_to_class_and_property_name(): void
    {
        $host = new AutoKeyResolverHost;

        $this->assertSame(
            AutoKeyResolverHost::class . ':owner',
            $host->resolver->resolve(),
        );
    }

    public function test_memoizes_the_resolved_key(): void
    {
        $host = new AutoKeyResolverHost;

        $first = $host->resolver->resolve();

        // Swap the property so a re-walk would no longer find the original owner.
        $host->owner = new stdClass;

        $second = $host->resolver->resolve();

        $this->assertSame($first, $second);
    }
}

final class AutoKeyResolverHost
{
    public stdClass $owner;
    public AutoKeyResolver $resolver;

    public function __construct()
    {
        $this->owner = new stdClass;
        $this->resolver = new AutoKeyResolver($this->owner);
    }
}

final class AutoKeyResolverOrphanHost
{
    public AutoKeyResolver $resolver;

    public function __construct()
    {
        // Owner built inline and never stored, so no property of the source matches.
        $this->resolver = new AutoKeyResolver(new stdClass);
    }
}
