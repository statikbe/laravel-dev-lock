<?php

declare(strict_types=1);

use Statikbe\DevLock\Tests\LockedEnvironmentTestCase;

beforeEach(function () {
    $this->lockWebGroup();
});

it('passes every request through while the lock is off', function () {
    config()->set('dev-lock.dev_enabled', false);

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

it('trims whitelisted IPs given as a comma separated string', function () {
    config()->set('dev-lock.dev_whitelist_ips', '10.0.0.8, '.LockedEnvironmentTestCase::WHITELISTED_IP);

    $this->withServerVariables(['REMOTE_ADDR' => LockedEnvironmentTestCase::WHITELISTED_IP])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('dashboard behind the lock');
});

// Behind a tunnel or a same-host reverse proxy every visitor arrives from loopback, so the
// shipped default must not let loopback through.
it('does not let loopback traffic through with the shipped whitelist', function () {
    $shipped = require __DIR__.'/../../config/dev-lock.php';
    config()->set('dev-lock.dev_whitelist_ips', $shipped['dev_whitelist_ips']);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/dashboard')
        ->assertRedirect(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']));
});

it('shows the password form with hardened headers', function () {
    $response = $this->get(route('dev.lock'));

    $response->assertStatus(401)
        ->assertSee('Restrict Access')
        ->assertSee('name="dev_lock_password"', escape: false)
        ->assertSee('action="http://localhost/__dev-lock"', escape: false)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('max-age=0');
});

it('gives password managers a sign-in form to recognise', function () {
    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('autocomplete="username"', escape: false)
        ->assertSee('autocomplete="current-password"', escape: false)
        ->assertSee('name="dev_lock_username"', escape: false)
        ->assertSee('value="dev"', escape: false);
});

it('fills the hidden username field from the config', function () {
    config()->set('dev-lock.dev_username', 'statik');

    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('value="statik"', escape: false);
});

it('hides the username field with a style, never with type="hidden"', function () {
    $response = $this->get(route('dev.lock'))->assertStatus(401);

    preg_match('/<input[^>]*id="dev_lock_username"[^>]*>/', (string) $response->getContent(), $field);

    // A password manager skips type="hidden" inputs, so this one has to stay a text
    // input that CSS hides. Inline, because the page's stylesheet is not guaranteed.
    expect($field[0] ?? '')
        ->toContain('type="text"')
        ->toContain('style="display: none"');
});

it('ignores whatever a password manager autofilled into the username field', function () {
    $this->post(route('dev.lock.submit'), [
        'dev_lock_username' => 'not-the-configured-username',
        'dev_lock_password' => LockedEnvironmentTestCase::PASSWORD,
    ])
        ->assertRedirect('/')
        ->assertSessionHas('dev_lock_authenticated', $this->unlockedWith());
});

it('unlocks the session and returns the visitor to their destination on the correct password', function () {
    $this->post(route('dev.lock.submit', ['redirect_to' => 'http://localhost/dashboard']), [
        'dev_lock_password' => LockedEnvironmentTestCase::PASSWORD,
    ])
        ->assertRedirect('http://localhost/dashboard')
        ->assertSessionHas('dev_lock_authenticated', $this->unlockedWith());
});

it('lets an unlocked session reach the application', function () {
    $this->withSession(['dev_lock_authenticated' => $this->unlockedWith()])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('dashboard behind the lock');
});

it('locks out sessions unlocked with a password that has since been changed', function () {
    $unlockedWithOldPassword = $this->unlockedWith();
    config()->set('dev-lock.dev_password', 'a-rotated-password');

    $this->withSession(['dev_lock_authenticated' => $unlockedWithOldPassword])
        ->get('/dashboard')
        ->assertRedirect(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']));
});

// Sessions unlocked before the session was tied to the password stored a plain `true`.
it('does not accept the old boolean session flag', function () {
    $this->withSession(['dev_lock_authenticated' => true])
        ->get('/dashboard')
        ->assertRedirect(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']));
});

it('returns an unlocked session away from the password form', function () {
    $this->withSession(['dev_lock_authenticated' => $this->unlockedWith()])
        ->get(route('dev.lock', ['redirect_to' => 'http://localhost/dashboard']))
        ->assertRedirect('http://localhost/dashboard');
});

it('sends the visitor back to the form with an error on the wrong password', function () {
    $this->post(route('dev.lock.submit'), ['dev_lock_password' => 'not-the-password'])
        ->assertRedirect(route('dev.lock'))
        ->assertSessionHas('dev_lock_error', 'The password is incorrect.')
        ->assertSessionMissing('dev_lock_authenticated');
});

it('refuses to redirect to another host after unlocking', function () {
    $this->post(route('dev.lock.submit', ['redirect_to' => 'https://evil.example']), [
        'dev_lock_password' => LockedEnvironmentTestCase::PASSWORD,
    ])
        ->assertSessionHas('dev_lock_authenticated', $this->unlockedWith())
        ->assertRedirect('/');
});

it('refuses off-host redirect targets for an already unlocked session', function () {
    $this->withSession(['dev_lock_authenticated' => $this->unlockedWith()])
        ->get(route('dev.lock', ['redirect_to' => 'https://evil.example/phishing']))
        ->assertRedirect('/');
});

it('rejects a non string password without blowing up', function () {
    $this->post(route('dev.lock.submit'), ['dev_lock_password' => ['array-instead-of-string']])
        ->assertRedirect(route('dev.lock'))
        ->assertSessionMissing('dev_lock_authenticated');
});

it('throttles an IP after five failed attempts, even when the password is right', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('dev.lock.submit'), ['dev_lock_password' => 'not-the-password'])
            ->assertSessionHas('dev_lock_error', 'The password is incorrect.');
    }

    $response = $this->post(route('dev.lock.submit'), [
        'dev_lock_password' => LockedEnvironmentTestCase::PASSWORD,
    ]);

    $response->assertRedirect(route('dev.lock'))
        ->assertSessionMissing('dev_lock_authenticated');

    expect(session('dev_lock_error'))->toStartWith('Too many login attempts.');
});

it('denies access instead of showing a form when no password is configured', function () {
    config()->set('dev-lock.dev_password', null);

    $this->get('/dashboard')
        ->assertStatus(503)
        ->assertDontSee('dashboard behind the lock')
        ->assertSee('DEV_LOCK_PASSWORD', escape: false);
});

it('denies whitelisted IPs and unlocked sessions too when no password is configured', function () {
    config()->set('dev-lock.dev_password', null);

    $this->withSession(['dev_lock_authenticated' => $this->unlockedWith()])
        ->withServerVariables(['REMOTE_ADDR' => LockedEnvironmentTestCase::WHITELISTED_IP])
        ->get('/dashboard')
        ->assertStatus(503)
        ->assertDontSee('dashboard behind the lock');
});

it('never authenticates against an empty configured password', function () {
    config()->set('dev-lock.dev_password', '');

    $this->post(route('dev.lock.submit'), ['dev_lock_password' => ''])
        ->assertStatus(503)
        ->assertSessionMissing('dev_lock_authenticated');
});
