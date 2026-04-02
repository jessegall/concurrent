<?php

namespace JesseGall\Concurrent;

/**
 * @template TValue
 *
 * @extends Concurrent<TValue>
 *
 * @deprecated Use Concurrent directly — it now supports auto-key resolution when no key is provided. Will be removed in v2.0.0.
 */
class ConcurrentClassMember extends Concurrent
{
}
