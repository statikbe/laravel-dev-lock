---
name: statik-dev-lock-development
description: >
  Configure and apply the Laravel Statik Dev Lock package in Laravel applications, including
  replacing an app's own hand-rolled dev lock middleware, view and routes with the package.
license: MIT
metadata:
  author: Kristof
---

# Laravel Statik Dev Lock

Use this skill when a Laravel application needs to password protect a non-public environment
(staging, acceptance, client preview) with the `statikbe/statik-dev-lock` package, or when it
already has its own copy of that lock that should be replaced by the package.

## Primary Goal

- apply the `statikbe/statik-dev-lock` package's public API in the smallest correct way
- leave exactly one dev lock in the app: this package's

The package's entire public surface is one middleware,
`Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware`, plus its config, view and
translations. There is no facade, no command and no class to call.

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project with `bootstrap/app.php`, or an `app/Http/Kernel.php` kept
  from before the slim skeleton
- check whether `config/statik-dev-lock.php` has already been published (step 3 publishes it if not)
- check whether the app builds Tailwind through `resources/css/app.css`
- note the version: the package requires Laravel 12 or 13 on PHP 8.3+, which is exactly what its CI
  covers. On Laravel 11 or older, stop and tell the user to upgrade the app first — those branches
  are past security support and Composer will not install them alongside this package

### 2. Find an existing hand-rolled dev lock and list it

Most apps that install this package already carry a copy of the same lock in their own
namespace. Always search before registering anything, because an app-local middleware named
`StatikDevLockMiddleware` and the package one are indistinguishable at a glance in
`bootstrap/app.php`.

```bash
# the shared vocabulary of a copied lock
grep -rn "statik_dev_authenticated\|statik_dev_password\|dev_lock_error" app bootstrap config routes resources tests
# route names, registrations and class references
grep -rn "dev\.lock\|DevLock\|dev-lock" app bootstrap config routes resources tests
```

Report every hit as a list before touching a file, mapping each one to what replaces it:

| Found in the app | Replaced by |
| --- | --- |
| `app/Http/Middleware/StatikDevLockMiddleware.php` (any app-namespaced copy) | `Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware` |
| `resources/views/dev-lock.blade.php` and its includes | `statik-dev-lock::dev-lock` |
| `dev.lock` / `dev.lock.submit` routes in `routes/web.php`, plus any controller behind them | `routes/statik-dev-lock.php` in the package |
| an app config file such as `config/dev-lock.php` | `config/statik-dev-lock.php` |
| `dev_lock` translation lines in `lang/*/*.php` | `statik-dev-lock::messages.dev_lock.*` |
| `App\Http\Middleware\StatikDevLockMiddleware::class` in `bootstrap/app.php` (or `$middlewareGroups` / `$routeMiddleware` in `app/Http/Kernel.php` on an app that kept its HTTP kernel) | the same registration with the package's FQCN |
| app tests referencing the old class | retarget to the package class, do not delete the tests |

Two details worth checking explicitly:

- `resources/views/dev-lock.blade.php` is *not* this package's published view. Publishing writes
  to `resources/views/vendor/statik-dev-lock/dev-lock.blade.php`, so a file directly under
  `resources/views` is the app's own copy and is safe to remove.
- an app-local `Route::get('/__dev-lock')->name('lock')` collides with the package route: same URI,
  same name, and which one answers depends on registration order. Remove the app's routes instead
  of reasoning about the order.

### 3. Publish the package config before removing anything

Always publish the config first, so the old lock's settings have somewhere to move to:

```bash
php artisan vendor:publish --tag="statik-dev-lock-config"
```

If the app already has `config/statik-dev-lock.php`, leave it as it is. `vendor:publish` needs
`--force` to overwrite an existing file, and forcing it discards exactly the values being migrated.

Then move each old setting to its new home. Only one of the four keys has no env variable, so most
of this is env work rather than config work:

| Old app setting | New key | Where it belongs |
| --- | --- | --- |
| on/off flag | `dev_enabled` | `STATIK_DEV_LOCK_ENABLED` in `.env` |
| the password | `dev_password` | `STATIK_DEV_LOCK_PASSWORD` in `.env` |
| allowed / whitelisted IPs | `dev_whitelist_ips` | `STATIK_DEV_LOCK_WHITELIST_IPS`, or the array in the published config |
| excluded / skipped paths | `dev_skip_patterns` | **the published config only** — this key has no env variable |

Carry over every path the app exempted. The package ships `['up', 'api/*']`, so an app that also
skipped a webhook or callback URL loses it silently unless it is added:

```php
// config/statik-dev-lock.php
'dev_skip_patterns' => [
    'up',
    'api/*',
    'webhooks/*',   // ported from the app's own lock
],
```

Skipped paths are *unprotected*, so port what the environment genuinely needs and no more. Never
move a password into the published config — it belongs in the environment.

### 4. Remove the old implementation

Confirm the list with the user, then delete the app copies and swap the registration in one pass,
so the app is never running two locks at once:

- delete the app middleware, the app view, the app routes and the app config file — the config file
  only after step 3 has captured its values
- replace the `use` import and the class reference in `bootstrap/app.php` (or `app/Http/Kernel.php`)
  with the package's FQCN
- rename the old env variables to `STATIK_DEV_LOCK_*` in `.env`, `.env.example` **and** in the
  deploy or CI env templates, otherwise the lock silently switches off on the next deploy
- to keep custom markup, publish the package view and port the old design into it rather than
  keeping the app-local Blade file
- keep `dev_lock` translation lines only if something outside the lock still uses them

Verify:

```bash
php artisan optimize:clear
php artisan route:list --path=__dev-lock   # only the package routes remain
grep -rn "App\\\\Http\\\\Middleware\\\\StatikDevLockMiddleware" app bootstrap routes tests   # no hits
```

Stale `bootstrap/cache/config.php` and `bootstrap/cache/routes-*.php` keep the deleted routes and
class alive, which is why `optimize:clear` comes before the checks.

### 5. Register the middleware on the `web` group

```php
// bootstrap/app.php
use Illuminate\Foundation\Configuration\Middleware;
use Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', StatikDevLockMiddleware::class);
})
```

In an app that still has `app/Http/Kernel.php`, append the middleware to the `web` entry of
`$middlewareGroups` instead:

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ... the rest of the group
        \Statikbe\StatikDevLock\Http\Middleware\StatikDevLockMiddleware::class,
    ],
];
```

The `web` group is required. The middleware recognises its own password routes with
`$request->routeIs('dev.lock')`, and global middleware runs before the router resolves a
route, so `append()` (or `$middleware` outside the `web` group) makes the password page
redirect to itself.

### 6. Set the environment variables

```dotenv
STATIK_DEV_LOCK_ENABLED=true
STATIK_DEV_LOCK_PASSWORD="a long password you can share with the client"
# optional, array or comma separated string, defaults to 127.0.0.1 and localhost
STATIK_DEV_LOCK_WHITELIST_IPS="1.2.3.4,5.6.7.8"
```

Never commit a password, and never enable the lock without one: the middleware then answers
every protected request with a `503` naming the missing variable.

### 7. Adjust what stays reachable

`dev_skip_patterns` in the config published in step 3 decides what stays reachable while the lock is
on, matched with `Request::is()` so wildcards work. It defaults to `up` and `api/*`; add the health
check, webhook or callback paths this environment needs, and nothing beyond them.

### 8. Make the password page render

The shipped view uses Tailwind utilities and `@vite('resources/css/app.css')`. With Tailwind
v4, register the package views as a source or the page renders unstyled:

```css
/* resources/css/app.css */
@source '../../vendor/statikbe/statik-dev-lock/resources/views';
```

Publish the view to restyle it or to remove the `@vite` dependency, and the translations to
change the wording:

```bash
php artisan vendor:publish --tag="statik-dev-lock-views"
php artisan vendor:publish --tag="statik-dev-lock-lang"
```

Publish tags: `statik-dev-lock`, `statik-dev-lock-config`, `statik-dev-lock-views`,
`statik-dev-lock-lang`.

## Rules, References, and Templates

Read before executing:

- `config/statik-dev-lock.php` for the four config keys and their env variables
- `resources/views/dev-lock.blade.php` for the markup a host app can replace
- `README.md` for the full behaviour description

Behaviour the app can rely on:

- routes `dev.lock` (GET `/__dev-lock`) and `dev.lock.submit` (POST) are registered only while
  `statik-dev-lock.dev_enabled` is `true`, in the `web` group
- the password form answers `401` with `X-Frame-Options: DENY`, `X-Robots-Tag: noindex, nofollow`
  and a `no-store` `Cache-Control`
- unlocking stores `statik_dev_authenticated` in the session and regenerates the session id
- failed attempts are rate limited per IP: 5 attempts, then a 5 minute lockout
- `?redirect_to=` only honours `http`/`https` URLs on the app's own host; anything else lands on `/`

## Examples

- Replacing an app's own lock: `grep` turns up `app/Http/Middleware/StatikDevLockMiddleware.php`,
  `resources/views/dev-lock.blade.php`, two `dev.lock` routes in `routes/web.php`, a
  `config/dev-lock.php` exempting `webhooks/*`, and an
  `appendToGroup('web', App\Http\Middleware\StatikDevLockMiddleware::class)` line. List all five,
  publish `statik-dev-lock-config` and copy `webhooks/*` into its `dev_skip_patterns` *before*
  deleting `config/dev-lock.php`, delete the other app copies, point the registration at the package
  class, rename the env variables, then `php artisan optimize:clear` and confirm
  `route:list --path=__dev-lock` shows only the package routes.
- Locking an acceptance environment: register the middleware on the `web` group, set
  `STATIK_DEV_LOCK_ENABLED=true` and `STATIK_DEV_LOCK_PASSWORD` in that environment only, and
  leave production untouched so the lock stays off there.
- Keeping a webhook reachable: publish the config and add the webhook path to
  `dev_skip_patterns`, for example `['up', 'api/*', 'webhooks/*']`. Publishing is the only way to
  change this key — it has no env variable.
- Verifying the lock in a feature test: assert an unauthenticated request to a protected page
  redirects to `route('dev.lock')`, and that a request with
  `session(['statik_dev_authenticated' => true])` reaches the page.

## Anti-patterns

- registering the package middleware while the app's own copy is still registered: whichever runs
  first wins, and the app's config, view and password keep being used
- deleting an app's dev lock files before listing them and confirming the removal
- deleting the app's own config file before publishing `config/statik-dev-lock.php` and porting its
  values across, which silently drops custom skip patterns and IP whitelists
- republishing the config with `--force` after the migration, which overwrites the ported values
- expecting `dev_skip_patterns` to be configurable through the environment: it is the one key with
  no env variable, so the config must be published to change it
- treating `resources/views/dev-lock.blade.php` as this package's published view, or leaving it in
  place expecting the package to pick it up
- leaving app-local `dev.lock` routes in `routes/web.php` next to the package routes
- swapping the class in `bootstrap/app.php` but leaving the old `DEV_*` env variables, which
  disables the lock on the next deploy
- registering the middleware globally with `append()` instead of `appendToGroup('web', ...)`
- enabling the lock without `STATIK_DEV_LOCK_PASSWORD`, or hardcoding a password in the config
- treating the lock as real authentication: it hides an environment, it does not protect user data
- adding `api/*` routes to the skip patterns and assuming they are protected
- checking the `statik_dev_authenticated` session key in application code instead of relying
  on the middleware
