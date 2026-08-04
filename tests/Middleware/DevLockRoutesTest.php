<?php

declare(strict_types=1);

it('registers the dev lock routes the middleware looks for', function () {
    $lock = app('router')->getRoutes()->getByName('dev.lock');
    $submit = app('router')->getRoutes()->getByName('dev.lock.submit');

    expect($lock?->uri())->toBe('__dev-lock')
        ->and($lock?->methods())->toContain('GET')
        ->and($submit?->uri())->toBe('__dev-lock')
        ->and($submit?->methods())->toContain('POST');
});

it('runs the dev lock routes in the web group, so they get a session', function () {
    expect(app('router')->getRoutes()->getByName('dev.lock')?->gatherMiddleware())->toContain('web');
});

it('falls back to the home page when the middleware is not registered', function () {
    // Without the middleware there is no form to show; the route action must still be safe.
    $this->get(route('dev.lock'))->assertRedirect('/');
    $this->post(route('dev.lock.submit'))->assertRedirect('/');
});
