<?php

namespace JesseGall\Concurrent\Contracts;

interface KeyResolver
{
    public function resolve(): string;
}
