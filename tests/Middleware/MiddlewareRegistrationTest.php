<?php

declare(strict_types=1);

it('protects the application when registered on the web group', function () {
    $this->lockWebGroup();

    $this->get('/dashboard')->assertRedirect(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']));
    $this->get(route('dev.lock'))->assertStatus(401);
});

it('cannot show the password form when registered as global middleware', function () {
    // Global middleware runs before the router dispatches, so $request->route() is null and
    // routeIs('dev.lock') can never be true: the lock page redirects to itself forever
    // instead of rendering the form. This is why the README says the web group.
    $this->lockGlobally();

    $response = $this->get('/__dev-lock');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toStartWith('http://localhost/__dev-lock?redirect_to=');
});
