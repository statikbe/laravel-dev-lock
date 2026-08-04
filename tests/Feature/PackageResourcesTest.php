<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

it('merges the package config with a lock that is off and without a default password', function () {
    expect(config('statik-dev-lock.dev_enabled'))->toBeFalse()
        ->and(config('statik-dev-lock.dev_password'))->toBeNull()
        ->and(config('statik-dev-lock.dev_skip_patterns'))->toBe(['up', 'api/*'])
        ->and(config('statik-dev-lock.dev_whitelist_ips'))->toBe(['127.0.0.1', 'localhost']);
});

it('loads the package translations', function () {
    expect(trans('statik-dev-lock::messages.dev_lock'))
        ->toHaveKeys(['title', 'enter_password', 'access_site', 'site_in_dev_mode', 'api_endpoints_remain', 'not_configured']);
});

it('translates the lock in english and dutch', function () {
    expect(trans('statik-dev-lock::messages.dev_lock.title', locale: 'en'))->toBe('Restrict Access')
        ->and(trans('statik-dev-lock::messages.dev_lock.title', locale: 'nl'))->toBe('Beperkte toegang');
});

it('loads the package views', function () {
    expect(view()->exists('statik-dev-lock::dev-lock'))->toBeTrue();
});

it('publishes the config, views, translations and fallback stylesheet but ships no assets', function () {
    expect(ServiceProvider::publishableGroups())
        ->toContain(
            'statik-dev-lock',
            'statik-dev-lock-config',
            'statik-dev-lock-views',
            'statik-dev-lock-lang',
            'statik-dev-lock-css',
        )
        ->not->toContain('statik-dev-lock-assets');
});

it('publishes the fallback stylesheet where the middleware looks for it', function () {
    $paths = ServiceProvider::pathsToPublish(null, 'statik-dev-lock-css');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('/resources/css')
        ->and(reset($paths))->toBe(resource_path('css/vendor/statik-dev-lock'));
});

it('actually copies the fallback stylesheet when the css tag is published', function () {
    $target = resource_path('css/vendor/statik-dev-lock/dev-lock.css');

    try {
        $this->artisan('vendor:publish', ['--tag' => 'statik-dev-lock-css'])->assertSuccessful();

        expect($target)->toBeReadableFile()
            ->and(file_get_contents($target))->toContain('#dev-lock-card');
    } finally {
        // The middleware prefers this copy, so leaving it behind would leak into other tests.
        is_file($target) && unlink($target);
    }
});

it('does not register the dev lock routes while the lock is off', function () {
    expect(app('router')->has('dev.lock'))->toBeFalse()
        ->and(app('router')->has('dev.lock.submit'))->toBeFalse();
});
