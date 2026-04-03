<?php

namespace JesseGall\Concurrent\Tests;

use JesseGall\Concurrent\Concurrent;
use JesseGall\Concurrent\Laravel\ConcurrentServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ConcurrentServiceProvider::class,
        ];
    }

    protected function tearDown(): void
    {
        Concurrent::resetDrivers();

        parent::tearDown();
    }
}
