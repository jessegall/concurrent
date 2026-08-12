<?php

namespace JesseGall\Concurrent;

use Closure;
use JesseGall\Concurrent\Attributes\ReadonlyMethod;
use JesseGall\Concurrent\Exceptions\ReadonlyViolationException;
use ReflectionMethod;
use Throwable;

/**
 * Decides whether a method on the wrapped value is read-only and runs it
 * without locking. Mutation of the target during the call throws
 * ReadonlyViolationException so silent write loss is caught early.
 *
 * @internal
 */
final class ReadonlyMethodInvoker
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /**
     * @param list<string> $declaredReadOnlyMethods
     * @param Closure(mixed, string, array<int, mixed>): mixed $forwarder
     */
    public function __construct(
        private readonly string $scope,
        private readonly array $declaredReadOnlyMethods,
        private readonly Closure $forwarder,
    ) {}

    public function isReadOnly(mixed $target, string $name): bool
    {
        $targetClass = is_object($target) ? $target::class : '_';
        $key = "{$this->scope}|{$targetClass}|{$name}";

        return self::$cache[$key] ??= $this->compute($target, $name);
    }

    /**
     * @param array<int, mixed> $arguments
     *
     * @throws ReadonlyViolationException If the method mutates the target.
     */
    public function invoke(mixed $target, string $name, array $arguments): mixed
    {
        $before = $this->snapshot($target);
        $result = ($this->forwarder)($target, $name, $arguments);

        if ($before !== null && $this->snapshot($target) !== $before) {
            $class = is_object($target) ? $target::class : gettype($target);

            throw new ReadonlyViolationException(
                "Method {$class}::{$name}() is declared read-only but mutated the wrapped value."
            );
        }

        return $result;
    }

    /**
     * A comparable image of the value, or null when it cannot produce one.
     *
     * Not every value is serializable — a closure, a resource handle, a test double. Reading
     * one is still perfectly legal, so the mutation check steps aside rather than turning a
     * working read into a crash.
     */
    private function snapshot(mixed $target): ?string
    {
        try {
            return serialize($target);
        } catch (Throwable) {
            return null;
        }
    }

    private function compute(mixed $target, string $name): bool
    {
        if (in_array($name, $this->declaredReadOnlyMethods, true)) {
            return true;
        }

        if (! is_object($target) || ! method_exists($target, $name)) {
            return false;
        }

        return new ReflectionMethod($target, $name)->getAttributes(ReadonlyMethod::class) !== [];
    }
}
