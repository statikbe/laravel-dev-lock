<div align="center">
    <h1>Laravel Statik Dev Lock</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/statikbe/statik-dev-lock"><img src="https://img.shields.io/packagist/v/statikbe/statik-dev-lock.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/statikbe/statik-dev-lock"><img src="https://img.shields.io/packagist/php-v/statikbe/statik-dev-lock.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/statikbe/statik-dev-lock"><img src="https://badge.laravel.cloud/badge/statikbe/statik-dev-lock?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/statikbe/laravel-statik-dev-lock/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/statikbe/laravel-statik-dev-lock/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/statikbe/statik-dev-lock"><img src="https://img.shields.io/packagist/dt/statikbe/statik-dev-lock.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Password protects environments that should not be publicly reachable — staging, acceptance, a client preview — without touching `.htaccess`, basic auth or your web server config.

The whole package is one middleware. Turn it on with an env variable, add it to the `web` middleware group, and every request has to pass a password form first. Health checks and API routes stay reachable, and so do whitelisted IPs.

> [!NOTE]
> This hides an environment, it is not authentication. It keeps crawlers, search engines and
> forwarded links out of a staging site behind one shared password; it is not a substitute for
> protecting user data.

## Requirements

|         | Supported | Covered by CI |
|---------|-----------|---------------|
| PHP     | 8.3+      | 8.3, 8.4, 8.5 |
| Laravel | 12, 13    | 12, 13        |

Everything the package claims is proven on every push: the matrix is exactly its Composer
constraints, on Linux and Windows, in both `prefer-lowest` and `prefer-stable` lanes.

> [!NOTE]
> Laravel 10 and 11 are deliberately out of scope. Both branches are past their security-support
> window and every release in them carries an advisory that will not be patched
> ([CVE-2026-48019](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq)),
> so Composer refuses to install them without disabling its advisory policy. On those versions,
> upgrade the app rather than the lock.

## Installation

Install the package via Composer:

```bash
composer require statikbe/statik-dev-lock
```

Register the middleware on the `web` group in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', StatikDevLockMiddleware::class);
})
```

Apps that still route middleware through `app/Http/Kernel.php` — upgraded from Laravel 10 and never
moved to the slim skeleton — append it to the same group there instead:

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ... the rest of the group
        \Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware::class,
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
STATIK_DEV_LOCK_ENABLED=true
STATIK_DEV_LOCK_PASSWORD="a long password you can share with the client"
```

That is all. The lock is off by default, so nothing changes on environments that do not set `STATIK_DEV_LOCK_ENABLED`.

## Configuration

| Env variable | Config key | Default | Description |
| --- | --- | --- | --- |
| `STATIK_DEV_LOCK_ENABLED` | `dev_enabled` | `false` | Switches the lock on. While it is off, requests pass through and the password routes are not even registered. |
| `STATIK_DEV_LOCK_PASSWORD` | `dev_password` | `null` | The password visitors have to enter. There is no default on purpose — see below. |
| `STATIK_DEV_LOCK_WHITELIST_IPS` | `dev_whitelist_ips` | `['127.0.0.1', 'localhost']` | IPs that never see the form. An array, or a comma separated string: `STATIK_DEV_LOCK_WHITELIST_IPS="1.2.3.4,5.6.7.8"`. |
| — | `dev_skip_patterns` | `['up', 'api/*']` | Paths that stay reachable while the lock is on. Matched with `Request::is()`, so wildcards work. |

Config keys live under the `statik-dev-lock` namespace, so `dev_enabled` is
`config('statik-dev-lock.dev_enabled')`.

> [!WARNING]
> Enabling the lock without setting `STATIK_DEV_LOCK_PASSWORD` fails closed: every protected
> request gets a `503` telling you the password is missing. It never renders a form nobody can
> get past, and it never lets requests through.

Publish the config file to change the skipped paths:

```bash
php artisan vendor:publish --tag="statik-dev-lock-config"
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

The shipped view uses Tailwind utility classes and the host app's compiled CSS:

```blade
@vite('resources/css/app.css')
```

With Tailwind v4 the class scanner has to see the package's Blade file, otherwise the page renders unstyled. Add it as a source in your CSS entrypoint:

```css
/* resources/css/app.css */
@source '../../vendor/statikbe/statik-dev-lock/resources/views';
```

Prefer your own markup, or an app that does not build Tailwind? Publish the view and edit it — including dropping the `@vite` line:

```bash
php artisan vendor:publish --tag="statik-dev-lock-views"
```

The page ships with English and Dutch translations. Publish them to change the wording:

```bash
php artisan vendor:publish --tag="statik-dev-lock-lang"
```

Or publish everything at once:

```bash
php artisan vendor:publish --tag="statik-dev-lock"
```

## Replacing your own dev lock

Plenty of apps already carry a copy of this lock in their own namespace, usually as
`app/Http/Middleware/StatikDevLockMiddleware.php` with a `resources/views/dev-lock.blade.php` and a
pair of `dev.lock` routes in `routes/web.php`. Find them before registering the package one — the
two classes are indistinguishable at a glance in `bootstrap/app.php`:

```bash
grep -rn "statik_dev_authenticated\|dev\.lock\|DevLock" app bootstrap config routes resources tests
```

**Publish this package's config before you delete the app's own**, so the existing settings have
somewhere to go:

```bash
php artisan vendor:publish --tag="statik-dev-lock-config"
```

Then move each setting across. Three of the four keys are env driven, so most of this is `.env`
work — but `dev_skip_patterns` has no env variable, which makes the published config the only place
to keep the paths your old lock exempted:

| Your old setting | Moves to | Where |
| --- | --- | --- |
| on/off flag | `dev_enabled` | `STATIK_DEV_LOCK_ENABLED` |
| the password | `dev_password` | `STATIK_DEV_LOCK_PASSWORD` |
| allowed / whitelisted IPs | `dev_whitelist_ips` | `STATIK_DEV_LOCK_WHITELIST_IPS`, or the config array |
| excluded / skipped paths | `dev_skip_patterns` | the published config only |

An app that also exempted a webhook or callback URL drops back to the `['up', 'api/*']` default
without an error, so carry those over explicitly:

```php
// config/statik-dev-lock.php
'dev_skip_patterns' => [
    'up',
    'api/*',
    'webhooks/*',   // ported from your own lock
],
```

Now delete the app copies, point the middleware registration at
`Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware`, rename the old env variables to
`STATIK_DEV_LOCK_*` in `.env`, `.env.example` and your deploy templates, and run
`php artisan optimize:clear` so cached routes and config stop resurrecting the deleted class.

Three things to watch:

- `resources/views/dev-lock.blade.php` is not this package's published view — publishing writes to
  `resources/views/vendor/statik-dev-lock/`, so a file directly under `resources/views` is the app's
  own and can go.
- App-local `/__dev-lock` routes collide with the package's on both URI and name, and which one
  answers depends on registration order. Remove them rather than reason about the order.
- Once you have ported your values, do not re-run `vendor:publish` with `--force` for the config
  tag — it overwrites `config/statik-dev-lock.php` with the package defaults and takes the ported
  skip patterns with it. And keep the password in the environment, never in the published file.

The bundled Boost skill walks an agent through exactly this — see below.

## AI agents (Laravel Boost)

The package ships a [Laravel Boost](https://github.com/laravel/boost) skill,
`statik-dev-lock-development`, at `resources/boost/skills/`. It teaches an agent how to adopt the
package: registering the middleware on the `web` group, the env variables, the publish tags, the
Tailwind `@source` line, and replacing an app's own dev lock.

Boost finds it automatically in any app that has this package installed. To enable it:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Pick your agents when prompted, and select `statikbe/statik-dev-lock` at *"Which third-party AI
guidelines/skills would you like to install?"*. To refresh only the skills on a project that
already uses Boost:

```bash
php artisan boost:install --skills
```

Boost copies the skill to `.ai/skills/statik-dev-lock-development` and links it into each agent's
skills directory — `.claude/skills/` for Claude Code, and the equivalent for Codex, Cursor, Copilot,
Gemini, Junie, Amp and OpenCode. Commit those files so the whole team's agents pick the skill up.

Not interested? Exclude it by name in the app's `config/boost.php`:

```php
'skills' => [
    'exclude' => ['statik-dev-lock-development'],
],
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Statik Dev Lock! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Kristof](https://github.com/statikbe)
- [All Contributors](../../contributors)

## License

Laravel Statik Dev Lock is open-sourced software licensed under the [MIT license](LICENSE.md).
