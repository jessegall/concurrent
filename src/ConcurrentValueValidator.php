<?php

namespace JesseGall\Concurrent;

use Closure;

readonly class ConcurrentValueValidator
{
    private Closure $validator;

    public function __construct(callable|null $validator)
    {
        if ($validator)
        {
            $this->validator = $validator(...);
        }
        else
        {
            $this->validator = fn (mixed $value): bool => true;
        }
    }

    public function valid(mixed $value): bool
    {
        if (is_null($value))
        {
            return true;
        }

        return ($this->validator)($value);
    }

    public function invalid(mixed $value): bool
    {
        return ! $this->valid($value);
    }
}
