<div align="center">
    <h1>Laravel Dev Lock</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/statikbe/laravel-dev-lock"><img src="https://img.shields.io/packagist/v/statikbe/laravel-dev-lock.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/statikbe/laravel-dev-lock"><img src="https://img.shields.io/packagist/php-v/statikbe/laravel-dev-lock.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/statikbe/laravel-dev-lock"><img src="https://badge.laravel.cloud/badge/statikbe/laravel-dev-lock?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/statikbe/laravel-dev-lock/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/statikbe/laravel-dev-lock/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/statikbe/laravel-dev-lock"><img src="https://img.shields.io/packagist/dt/statikbe/laravel-dev-lock.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Password protects environments that should not be publicly reachable — staging, acceptance, a client preview — without touching `.htaccess`, basic auth or your web server config.

The whole package is one middleware. Turn it on with an env variable, add it to the `web` middleware group, and every request has to pass a password form first. Health checks and API routes stay reachable, and so do whitelisted IPs.

> [!NOTE]
> This hides an environment, it is not authentication. It keeps crawlers, search engines and
> forwarded links out of a staging site behind one shared password; it is not a substitute for
> protecting user data.

## Requirements

|         | Supported  | Covered by CI |
|---------|------------|---------------|
| PHP     | 8.3+       | 8.3, 8.4, 8.5 |
| Laravel | 11, 12, 13 | 11, 12, 13    |

Everything the package claims is proven on every push: the matrix is its Composer constraints, on
Linux and Windows, in both `prefer-lowest` and `prefer-stable` lanes. The single exception is
Laravel 11 on PHP 8.5, which the matrix skips — that branch predates 8.5 and will not be patched
for it.

> [!WARNING]
> Laravel 11 is supported, but it is past its security-support window: every release in that branch
> carries an advisory that will not be patched
> ([CVE-2026-48019](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq)),
> so Composer refuses to install one unless `--no-security-blocking` is passed. Locking an
> environment does not make an unpatched framework safe — treat this as a way to keep existing
> Laravel 11 apps protected while they are being upgraded, not as a reason to stay.

> [!NOTE]
> Laravel 10 remains out of scope.

## Installation

Install the package via Composer:

```bash
composer require statikbe/laravel-dev-lock
```

Register the middleware on the `web` group in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Statikbe\DevLock\Http\Middleware\DevLockMiddleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', DevLockMiddleware::class);
})
```

Apps that still route middleware through `app/Http/Kernel.php` — upgraded from Laravel 10 and never
moved to the slim skeleton — append it to the same group there instead:

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ... the rest of the group
        \Statikbe\DevLock\Http\Middleware\DevLockMiddleware::class,
    ],
];
```

> [!IMPORTANT]
> It has to be the `web` group, not global middleware. The middleware identifies its own
> password routes with `$request->routeIs()`, and global middleware runs before the router
> resolves a route — registered with `append()` (or in `$middleware`/`$middlewareGroups`
> outside `web`) instead of `appendToGroup()` the password page redirects to itself instead
> of rendering the form.

Then set the environment variables on the environments you want locked:

```dotenv
DEV_LOCK_ENABLED=true
DEV_LOCK_PASSWORD="a long password you can share with the client"
```

That is all. The lock is off by default, so nothing changes on environments that do not set `DEV_LOCK_ENABLED`.

## Configuration

| Env variable | Config key | Default | Description |
| --- | --- | --- | --- |
| `DEV_LOCK_ENABLED` | `dev_enabled` | `false` | Switches the lock on. While it is off, requests pass through and the password routes are not even registered. |
| `DEV_LOCK_PASSWORD` | `dev_password` | `null` | The password visitors have to enter. There is no default on purpose — see below. |
| `DEV_LOCK_USERNAME` | `dev_username` | `'dev'` | Fills the form's hidden username field so password managers can save and autofill the lock. Never validated — see [Password managers](#password-managers). |
| `DEV_LOCK_WHITELIST_IPS` | `dev_whitelist_ips` | `['127.0.0.1', 'localhost']` | IPs that never see the form. An array, or a comma separated string: `DEV_LOCK_WHITELIST_IPS="1.2.3.4,5.6.7.8"`. |
| — | `dev_skip_patterns` | `['up', 'api/*']` | Paths that stay reachable while the lock is on. Matched with `Request::is()`, so wildcards work. |
| `DEV_LOCK_VITE_ENTRYPOINT` | `dev_vite_entrypoint` | `'resources/css/app.css'` | The compiled CSS used to style the password page. Point it at the entrypoint your app builds, or set it to `null` to always use the package's own stylesheet. |

Config keys live under the `dev-lock` namespace, so `dev_enabled` is
`config('dev-lock.dev_enabled')`.

> [!WARNING]
> Enabling the lock without setting `DEV_LOCK_PASSWORD` fails closed: every protected
> request gets a `503` telling you the password is missing. It never renders a form nobody can
> get past, and it never lets requests through.

Publish the config file to change the skipped paths:

```bash
php artisan vendor:publish --tag="dev-lock-config"
```

## How it works

While the lock is on, every request through the `web` group is checked in this order:

1. Paths matching `dev_skip_patterns` pass through — the `up` health check and `api/*` by default.
2. Sessions that already entered the password pass through.
3. Whitelisted IPs pass through.
4. Everything else is redirected to `/__dev-lock`, which answers `401` with the password form. The original URL travels along in `?redirect_to=`, so visitors land where they were going after unlocking.

A few details worth knowing:

- Failed attempts are rate limited per IP: 5 tries, then a 5 minute lockout.
- The session ID is regenerated on success, so a planted session cannot be promoted to an unlocked one.
- `?redirect_to=` only accepts `http`/`https` URLs on your own host; anything else lands on `/`. The lock cannot be used as an open redirect.
- The form is served with `X-Frame-Options: DENY`, `X-Robots-Tag: noindex, nofollow` and a `no-store` `Cache-Control`.

## The password page

The page is styled with Tailwind utility classes and your app's compiled CSS. With Tailwind v4 the class scanner has to see the package's Blade file, otherwise the page renders unstyled. Add it as a source in your CSS entrypoint:

```css
/* resources/css/app.css */
@source '../../vendor/statikbe/laravel-dev-lock/resources/views';
```

Building a different entrypoint? Point the config at it instead of publishing anything:

```dotenv
DEV_LOCK_VITE_ENTRYPOINT="resources/css/site.css"
```

**If your app has no Vite build, the page still works.** The lock gates every request, so a page that cannot render would leave you with no way into the environment. Rather than calling `@vite` in the view, the middleware resolves your compiled CSS and inlines the package's own `resources/css/dev-lock.css` when it cannot — which covers an app that does not use Vite at all, a deploy where the frontend build never ran, and an entrypoint that is not in the manifest. The fallback page is plain but fully usable, and nothing needs publishing to get it. Set `DEV_LOCK_VITE_ENTRYPOINT=null` to skip Vite entirely and always use it.

To restyle the fallback, publish the stylesheet and edit it — the middleware prefers your copy over the packaged one:

```bash
php artisan vendor:publish --tag="dev-lock-css"
```

Prefer your own markup? Publish the view and edit it:

```bash
php artisan vendor:publish --tag="dev-lock-views"
```

If you do, keep the `dev-lock-card`, `dev-access-heading` and `dev-lock-note` ids — they are the only hooks the fallback stylesheet has. Keep the hidden username field too, for the reason below.

## Password managers

A shared password that has to be typed on every new session is a password people paste from somewhere less safe than a vault, so the form is built to be saved and autofilled: the password input carries `autocomplete="current-password"`, and a hidden username field next to it carries `autocomplete="username"`. Without the username, managers do not read the page as a sign-in form at all and offer neither to save nor to fill it.

The username is presentation only. It is written into the form from `DEV_LOCK_USERNAME`, never read back on submit, and grants nothing on its own — the password is still the only thing that unlocks anything. All it has to do is stay stable, because a manager matches a saved entry on it. It defaults to `dev`.

It is hidden with an inline `display: none` rather than `type="hidden"`: managers skip hidden inputs, and an inline style holds whether the page gets your compiled CSS or the fallback stylesheet.

One thing the package cannot do for you: a saved entry matches on the site's domain. 1Password matches the registrable domain by default, so a single entry covers every subdomain of it — one entry for `example.com` fills on `staging.example.com` and `acceptance.example.com` alike. Hosts under different domains need either an entry each, or one entry with several website fields.

The page ships with English and Dutch translations. Publish them to change the wording:

```bash
php artisan vendor:publish --tag="dev-lock-lang"
```

Or publish everything at once:

```bash
php artisan vendor:publish --tag="dev-lock"
```

## Replacing your own dev lock

Plenty of apps already carry a copy of this lock in their own namespace, usually as
`app/Http/Middleware/DevLockMiddleware.php` with a `resources/views/dev-lock.blade.php` and a
pair of `dev.lock` routes in `routes/web.php`. Find them before registering the package one — the
two classes are indistinguishable at a glance in `bootstrap/app.php`:

```bash
grep -rn "dev_lock_authenticated\|dev\.lock\|DevLock" app bootstrap config routes resources tests
```

**Publish this package's config before you delete the app's own**, so the existing settings have
somewhere to go:

```bash
php artisan vendor:publish --tag="dev-lock-config"
```

Then move each setting across. All but one key is env driven, so most of this is `.env` work — but
`dev_skip_patterns` has no env variable, which makes the published config the only place to keep the
paths your old lock exempted:

| Your old setting | Moves to | Where |
| --- | --- | --- |
| on/off flag | `dev_enabled` | `DEV_LOCK_ENABLED` |
| the password | `dev_password` | `DEV_LOCK_PASSWORD` |
| allowed / whitelisted IPs | `dev_whitelist_ips` | `DEV_LOCK_WHITELIST_IPS`, or the config array |
| excluded / skipped paths | `dev_skip_patterns` | the published config only |

An app that also exempted a webhook or callback URL drops back to the `['up', 'api/*']` default
without an error, so carry those over explicitly:

```php
// config/dev-lock.php
'dev_skip_patterns' => [
    'up',
    'api/*',
    'webhooks/*',   // ported from your own lock
],
```

Now delete the app copies, point the middleware registration at
`Statikbe\DevLock\Http\Middleware\DevLockMiddleware`, rename the old env variables to
`DEV_LOCK_*` in `.env`, `.env.example` and your deploy templates, and run
`php artisan optimize:clear` so cached routes and config stop resurrecting the deleted class.

Three things to watch:

- `resources/views/dev-lock.blade.php` is not this package's published view — publishing writes to
  `resources/views/vendor/dev-lock/`, so a file directly under `resources/views` is the app's
  own and can go.
- App-local `/__dev-lock` routes collide with the package's on both URI and name, and which one
  answers depends on registration order. Remove them rather than reason about the order.
- Once you have ported your values, do not re-run `vendor:publish` with `--force` for the config
  tag — it overwrites `config/dev-lock.php` with the package defaults and takes the ported
  skip patterns with it. And keep the password in the environment, never in the published file.

The bundled Boost skill walks an agent through exactly this — see below.

## AI agents (Laravel Boost)

The package ships a [Laravel Boost](https://github.com/laravel/boost) skill,
`dev-lock-development`, at `resources/boost/skills/`. It teaches an agent how to adopt the
package: registering the middleware on the `web` group, the env variables, the publish tags, the
Tailwind `@source` line, and replacing an app's own dev lock.

Boost finds it automatically in any app that has this package installed. To enable it:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Pick your agents when prompted, and select `statikbe/laravel-dev-lock` at *"Which third-party AI
guidelines/skills would you like to install?"*. To refresh only the skills on a project that
already uses Boost:

```bash
php artisan boost:install --skills
```

Boost copies the skill to `.ai/skills/dev-lock-development` and links it into each agent's
skills directory — `.claude/skills/` for Claude Code, and the equivalent for Codex, Cursor, Copilot,
Gemini, Junie, Amp and OpenCode. Commit those files so the whole team's agents pick the skill up.

Not interested? Exclude it by name in the app's `config/boost.php`:

```php
'skills' => [
    'exclude' => ['dev-lock-development'],
],
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Dev Lock! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [statikbe](https://github.com/statikbe)
- [All Contributors](../../contributors)

## License

Laravel Dev Lock is open-sourced software licensed under the [MIT license](LICENSE.md).
