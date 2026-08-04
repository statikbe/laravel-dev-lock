<?php

declare(strict_types=1);

use Statikbe\StatikDevLock\Tests\LockedEnvironmentTestCase;

beforeEach(function () {
    $this->lockWebGroup();
    $this->withoutVite();
});

it('passes every request through while the lock is off', function () {
    config()->set('statik-dev-lock.dev_enabled', false);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('dashboard behind the lock');
});

it('sends an unauthenticated visitor to the password form and remembers the destination', function () {
    $this->get('/dashboard')
        ->assertRedirect(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']));
});

it('leaves skipped paths reachable', function (string $path, string $body) {
    $this->get($path)
        ->assertOk()
        ->assertSee($body);
})->with([
    'health check' => ['up', 'healthy'],
    'api endpoint' => ['api/status', 'api status'],
]);

it('lets whitelisted IPs straight through', function () {
    $this->withServerVariables(['REMOTE_ADDR' => LockedEnvironmentTestCase::WHITELISTED_IP])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('dashboard behind the lock');
});

it('shows the password form with hardened headers', function () {
    $response = $this->get(route('dev.lock'));

    $response->assertStatus(401)
        ->assertSee('Restrict Access')
        ->assertSee('name="statik_dev_password"', escape: false)
        ->assertSee('action="http://localhost/__dev-lock"', escape: false)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('max-age=0');
});

it('unlocks the session and returns the visitor to their destination on the correct password', function () {
    $this->post(route('dev.lock.submit', ['redirect_to' => 'http://localhost/dashboard']), [
        'statik_dev_password' => LockedEnvironmentTestCase::PASSWORD,
    ])
        ->assertRedirect('http://localhost/dashboard')
        ->assertSessionHas('statik_dev_authenticated', true);
});

it('lets an unlocked session reach the application', function () {
    $this->withSession(['statik_dev_authenticated' => true])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('dashboard behind the lock');
});

it('returns an unlocked session away from the password form', function () {
    $this->withSession(['statik_dev_authenticated' => true])
        ->get(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']))
        ->assertRedirect('http://localhost/dashboard');
});

it('sends the visitor back to the form with an error on the wrong password', function () {
    $this->post(route('dev.lock.submit'), ['statik_dev_password' => 'not-the-password'])
        ->assertRedirect(route('dev.lock'))
        ->assertSessionHas('dev_lock_error', 'The password is incorrect.')
        ->assertSessionMissing('statik_dev_authenticated');
});

it('refuses to redirect to another host after unlocking', function () {
    $this->post(route('dev.lock.submit', ['redirect_to' => 'https://evil.example']), [
        'statik_dev_password' => LockedEnvironmentTestCase::PASSWORD,
    ])
        ->assertSessionHas('statik_dev_authenticated', true)
        ->assertRedirect('/');
});

it('refuses off-host redirect targets for an already unlocked session', function () {
    $this->withSession(['statik_dev_authenticated' => true])
        ->get(route('dev.lock', ['redirect_to' => 'https://evil.example/phishing']))
        ->assertRedirect('/');
});

it('rejects a non string password without blowing up', function () {
    $this->post(route('dev.lock.submit'), ['statik_dev_password' => ['array-instead-of-string']])
        ->assertRedirect(route('dev.lock'))
        ->assertSessionMissing('statik_dev_authenticated');
});

it('throttles an IP after five failed attempts, even when the password is right', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('dev.lock.submit'), ['statik_dev_password' => 'not-the-password'])
            ->assertSessionHas('dev_lock_error', 'The password is incorrect.');
    }

    $response = $this->post(route('dev.lock.submit'), [
        'statik_dev_password' => LockedEnvironmentTestCase::PASSWORD,
    ]);

    $response->assertRedirect(route('dev.lock'))
        ->assertSessionMissing('statik_dev_authenticated');

    expect(session('dev_lock_error'))->toStartWith('Too many login attempts.');
});

it('denies access instead of showing a form when no password is configured', function () {
    config()->set('statik-dev-lock.dev_password', null);

    $this->get('/dashboard')
        ->assertStatus(503)
        ->assertDontSee('dashboard behind the lock')
        ->assertSee('STATIK_DEV_LOCK_PASSWORD', escape: false);
});

it('denies whitelisted IPs and unlocked sessions too when no password is configured', function () {
    config()->set('statik-dev-lock.dev_password', null);

    $this->withSession(['statik_dev_authenticated' => true])
        ->withServerVariables(['REMOTE_ADDR' => LockedEnvironmentTestCase::WHITELISTED_IP])
        ->get('/dashboard')
        ->assertStatus(503)
        ->assertDontSee('dashboard behind the lock');
});

it('never authenticates against an empty configured password', function () {
    config()->set('statik-dev-lock.dev_password', '');

    $this->post(route('dev.lock.submit'), ['statik_dev_password' => ''])
        ->assertStatus(503)
        ->assertSessionMissing('statik_dev_authenticated');
});
