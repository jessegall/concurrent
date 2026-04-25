<?php

namespace JesseGall\Concurrent\Attributes;

use Attribute;

/**
 * Marks a method on the wrapped value as read-only.
 *
 * Read-only methods skip locking and are not written back to cache.
 * If the method actually mutates the wrapped value, a
 * ReadonlyViolationException is thrown to surface the contract violation.
 *
 * Note: PHP reserves "Readonly" as a keyword, so the attribute is named
 * ReadonlyMethod to match the existing DeclaresReadOnlyMethods interface.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ReadonlyMethod
{
}
