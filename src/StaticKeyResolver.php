<?php

namespace JesseGall\Concurrent;

use JesseGall\Concurrent\Contracts\KeyResolver;

/**
 * Returns a fixed key passed in by the caller.
 *
 * @internal
 */
final class StaticKeyResolver implements KeyResolver
{
    public function __construct(private readonly string $key) {}

    public function resolve(): string
    {
        return $this->key;
    }
}
