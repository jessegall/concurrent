<?php

namespace JesseGall\Concurrent\Exceptions;

use LogicException;

/**
 * Thrown when a method declared read-only mutates the wrapped value.
 *
 * Read-only methods are not written back to cache, so any mutation they
 * perform would silently disappear on the next read. Surfacing the
 * violation as an exception prevents that class of bug.
 */
class ReadonlyViolationException extends LogicException
{
}
