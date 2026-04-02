<?php

namespace JesseGall\Concurrent\Contracts;

/**
 * When a concurrent object implements this interface, method calls listed in
 * readOnlyMethods() will skip locking and writing back to cache.
 */
interface DeclaresReadOnlyMethods
{
    /**
     * @return list<string>
     */
    public static function readOnlyMethods(): array;
}
