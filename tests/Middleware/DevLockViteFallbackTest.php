<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * The password form is the only page that can unlock a locked environment, so it has to render
 * whatever the host app's frontend build is doing. Testbench has no Vite manifest, which makes
 * these tests the real thing rather than a simulation: without the fallback the request 500s.
 */
beforeEach(function () {
    $this->lockWebGroup();
});

/**
 * A Vite that resolves, standing in for a host app with a built manifest.
 */
function fakeVite(string $href): Vite
{
    return new class($href) extends Vite
    {
        public function __construct(private string $href) {}

        public function __invoke($entrypoints, $buildDirectory = null)
        {
            return new HtmlString('<link rel="stylesheet" href="'.$this->href.'" />');
        }
    };
}

it('renders the password form when vite has no manifest', function () {
    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('Restrict Access')
        ->assertSee('name="dev_lock_password"', escape: false)
        ->assertSee('<style>', escape: false)
        ->assertDontSee('<link rel="stylesheet"', escape: false);
});

it('keeps a locked environment reachable when vite has no manifest', function () {
    $this->get('/dashboard')->assertRedirect(route('dev.lock', [
        'redirect_to' => 'http://localhost/dashboard',
    ]));

    $this->post(route('dev.lock.submit'), [
        'dev_lock_password' => 'wrong-password',
    ])->assertRedirect(route('dev.lock'));
});

it('inlines the package stylesheet rather than an empty style tag', function () {
    expect(dirname(__DIR__, 2).'/resources/css/dev-lock.css')->toBeReadableFile();

    // Selectors the fallback needs, proving the file was read and not merely wrapped in a tag.
    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('#dev-lock-card', escape: false)
        ->assertSee('prefers-color-scheme: dark', escape: false);
});

it('gives the fallback stylesheet the markup hooks it styles', function () {
    // The fallback works from ids because the Tailwind classes are inert without a build.
    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('id="dev-lock-card"', escape: false)
        ->assertSee('id="dev-access-heading"', escape: false)
        ->assertSee('id="dev-lock-note"', escape: false);
});

it('uses the host app compiled css when vite can resolve it', function () {
    $this->swap(Vite::class, fakeVite('/build/assets/app-real.css'));

    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('/build/assets/app-real.css', escape: false)
        ->assertDontSee('<style>', escape: false);
});

it('falls back when vite throws for a reason other than a missing manifest', function () {
    // An app whose CSS entry is not the configured one has a manifest that does not list it, which
    // is a different exception class than a missing manifest.
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null)
        {
            throw new RuntimeException('Unable to locate file in Vite manifest: resources/css/app.css.');
        }
    });

    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('name="dev_lock_password"', escape: false)
        ->assertSee('<style>', escape: false);
});

it('prefers a published stylesheet over the packaged one', function () {
    $published = resource_path('css/vendor/dev-lock/dev-lock.css');

    @mkdir(dirname($published), recursive: true);
    file_put_contents($published, 'body { content: "published override"; }');

    try {
        $this->get(route('dev.lock'))
            ->assertStatus(401)
            ->assertSee('published override', escape: false)
            ->assertDontSee('#dev-lock-card', escape: false);
    } finally {
        unlink($published);
    }
});

it('defaults the entrypoint to the conventional laravel one', function () {
    expect(config('dev-lock.dev_vite_entrypoint'))->toBe('resources/css/app.css');
});

it('passes the configured entrypoint to vite', function () {
    config()->set('dev-lock.dev_vite_entrypoint', 'resources/css/site.css');

    $this->swap(Vite::class, new class extends Vite
    {
        public mixed $seen = null;

        public function __invoke($entrypoints, $buildDirectory = null)
        {
            $this->seen = $entrypoints;

            return new HtmlString('<link rel="stylesheet" href="/build/site.css" />');
        }
    });

    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('/build/site.css', escape: false);

    expect(app(Vite::class)->seen)->toBe('resources/css/site.css');
});

it('skips vite entirely when the entrypoint is emptied', function (mixed $entrypoint) {
    config()->set('dev-lock.dev_vite_entrypoint', $entrypoint);

    // Proven by the fallback winning over a Vite that would otherwise have resolved.
    $this->swap(Vite::class, fakeVite('/build/assets/should-not-be-used.css'));

    $this->get(route('dev.lock'))
        ->assertStatus(401)
        ->assertSee('<style>', escape: false)
        ->assertDontSee('should-not-be-used.css', escape: false);
})->with([
    'null' => [null],
    'empty string' => [''],
]);
