<?php

declare(strict_types=1);

namespace Statikbe\DevLock\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Statikbe\DevLock\DevLockServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DevLockServiceProvider::class,
        ];
    }
}
