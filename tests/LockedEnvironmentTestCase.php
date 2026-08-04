<?php

declare(strict_types=1);

namespace Statikbe\StatikDevLock\Tests;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware;

/**
 * A Testbench app with the lock switched on, so the dev.lock routes are registered.
 *
 * The middleware is deliberately not registered here: each test picks the registration it
 * wants, which is how the `web` group requirement is proven instead of assumed.
 */
abstract class LockedEnvironmentTestCase extends TestCase
{
    public const PASSWORD = 'correct-horse-battery-staple';

    public const WHITELISTED_IP = '10.0.0.7';

    /**
     * Register the middleware the way the README tells host apps to.
     *
     * The HTTP kernel is the source of truth for middleware groups (its constructor syncs
     * them onto the router), which is what `$middleware->appendToGroup('web', ...)` in
     * bootstrap/app.php ends up calling.
     */
    protected function lockWebGroup(): void
    {
        $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', StatikDevLockMiddleware::class);
    }

    /**
     * Register the middleware globally, which runs before the router resolves a route.
     */
    protected function lockGlobally(): void
    {
        $this->app->make(HttpKernel::class)->pushMiddleware(StatikDevLockMiddleware::class);
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'session.driver' => 'array',
            'cache.default' => 'array',
            'statik-dev-lock.dev_enabled' => true,
            'statik-dev-lock.dev_password' => self::PASSWORD,
            'statik-dev-lock.dev_whitelist_ips' => [self::WHITELISTED_IP],
        ]);
    }

    /**
     * Stand-ins for the host app's own pages, matching the shipped skip patterns.
     *
     * @param  Router  $router
     */
    protected function defineWebRoutes($router): void
    {
        $router->get('/', fn () => 'home');
        $router->get('dashboard', fn () => 'dashboard behind the lock');
        $router->get('up', fn () => 'healthy');
        $router->get('api/status', fn () => 'api status');
    }
}
