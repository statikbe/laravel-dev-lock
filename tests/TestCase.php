<?php

declare(strict_types=1);

namespace Statikbe\StatikDevLock\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Statikbe\StatikDevLock\StatikDevLockServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            StatikDevLockServiceProvider::class,
        ];
    }
}
