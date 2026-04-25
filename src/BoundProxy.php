<?php

namespace JesseGall\Concurrent;

/**
 * Internal proxy used by Concurrent::set() when $this is bound inside a
 * zero-param closure. The closure runs with this proxy as $this; reads and
 * writes route to the wrapped data first, falling back to the Concurrent
 * wrapper for anything missing on the data.
 *
 * @internal
 */
final class BoundProxy
{
    private mixed $data;
    private Concurrent $wrapper;


    public function __construct(mixed &$data, Concurrent $wrapper)
    {
        $this->data = &$data;
        $this->wrapper = $wrapper;
    }

    public function &__get(string $name): mixed
    {
        if (is_object($this->data) && property_exists($this->data, $name)) {
            return $this->data->{$name};
        }

        if (is_array($this->data) && array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        return $this->wrapper->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        if (is_object($this->data)) {
            $this->data->{$name} = $value;

            return;
        }

        if (is_array($this->data)) {
            $this->data[$name] = $value;

            return;
        }

        $this->wrapper->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        if (is_object($this->data) && isset($this->data->{$name})) {
            return true;
        }

        if (is_array($this->data) && isset($this->data[$name])) {
            return true;
        }

        return isset($this->wrapper->{$name});
    }

    public function __unset(string $name): void
    {
        if (is_object($this->data) && property_exists($this->data, $name)) {
            unset($this->data->{$name});

            return;
        }

        if (is_array($this->data) && array_key_exists($name, $this->data)) {
            unset($this->data[$name]);

            return;
        }

        unset($this->wrapper->{$name});
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (is_object($this->data) && method_exists($this->data, $name)) {
            return $this->data->{$name}(...$arguments);
        }

        return $this->wrapper->{$name}(...$arguments);
    }
}
