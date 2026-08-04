<?php

declare(strict_types=1);

use Statikbe\DevLock\Tests\LockedEnvironmentTestCase;
use Statikbe\DevLock\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature');

// The lock has to be switched on before the application boots, otherwise the package never
// registers its dev.lock routes, so those tests need an application configured up front.
uses(LockedEnvironmentTestCase::class)->in(__DIR__.'/Middleware');
