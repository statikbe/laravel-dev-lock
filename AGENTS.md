# Laravel Statik Dev Lock

A Laravel package that password protects environments which should not be publicly reachable
(staging, acceptance, client preview) without `.htaccess` or server level basic auth.

The entire public surface is one middleware. Keep it that way: this package hides an environment
behind a shared password, it is not authentication and must not grow into it.

## Support Matrix

|           | Supported                                    |
|-----------|----------------------------------------------|
| PHP       | 8.3+                                         |
| Laravel   | 12, 13 (`illuminate/support: ^12.0\|^13.0`)  |
| Testbench | 10 (L12), 11 (L13)                           |

Laravel 10 and 11 are deliberately out of scope — past security support, and Composer refuses to
install them without disabling its advisory policy. CI runs the matrix exactly: PHP 8.3/8.4/8.5 ×
Laravel 12/13 × `prefer-lowest`/`prefer-stable`, on Linux and Windows.

`composer.json`, the README requirements table, and the CHANGELOG "Requirements" section must
agree. Change one, change all three.

## Two Names, Do Not Mix Them

- **Composer/Packagist package:** `statikbe/statik-dev-lock` — used by the Packagist and
  `badge.laravel.cloud` badges.
- **GitHub repository:** `statikbe/laravel-statik-dev-lock` — used by `composer.json`'s `homepage`,
  the CI workflow-status badge, and the CHANGELOG compare/tag links.

Using the package name in a repo URL silently breaks badges and links. This has happened before.

## Layout

```
src/StatikDevLockServiceProvider.php          register + boot wiring, publish tags
src/Http/Middleware/StatikDevLockMiddleware.php   the whole feature
config/statik-dev-lock.php                    4 env-driven keys
routes/statik-dev-lock.php                    dev.lock, dev.lock.submit — registered only when enabled
resources/views/dev-lock.blade.php            the password page
lang/{en,nl}/messages.php                     translations
resources/boost/skills/.../SKILL.md           bundled Laravel Boost skill (ships to consumers)
```

Namespaces: `Statikbe\StatikDevLock\` → `src/`, `Statikbe\StatikDevLock\Tests\` → `tests/`.

Config namespace is `statik-dev-lock`; env vars are prefixed `STATIK_DEV_LOCK_`. Publish tags are
`statik-dev-lock` plus `-config`, `-views`, `-lang`.

## Behaviour That Constrains Changes

- The middleware belongs on the **`web` group**, never global. It identifies its own routes with
  `$request->routeIs()`, which needs a resolved route; global middleware runs before the router
  dispatches and the password page would redirect to itself.
- Routes are only registered while `dev_enabled === true`. The middleware repeats that same strict
  check — without the routes there is no form to send anyone to.
- Enabling the lock with no password **fails closed**: a `503` naming the missing env variable, not
  a form nobody can pass. Never make it fail open.
- `?redirect_to=` is only honoured for `http`/`https` URLs on the app's own host. This is open
  redirect protection; do not loosen it.
- Failed attempts are rate limited per IP (5 tries, 5 minute lockout), and the session id is
  regenerated on success. Both are security properties with tests behind them.

## Quality Gates

`composer test` runs all four and all four must pass:

| Command               | Gate                                                      |
|-----------------------|-----------------------------------------------------------|
| `composer analyse`    | PHPStan level 7 over `src`, `config`, `routes` (Larastan) |
| `composer lint:check` | Pint, Laravel preset (`composer lint` to fix)             |
| `composer test:types` | Pest type coverage, hard `--min=100`                      |
| `composer test:unit`  | Pest, parallel                                            |

Arch tests forbid `dd()`, `ddd()`, `env()` and `exit()` in autoloaded code. `env()` belongs only in
`config/`, which is not PSR-4 autoloaded — read config through `config()` everywhere else.

## Testing Notes

- `tests/Feature` uses `TestCase`. `tests/Middleware` uses `LockedEnvironmentTestCase`, which
  switches the lock on in `defineEnvironment()` — the lock must be enabled **before the app boots**
  or the routes never register.
- `LockedEnvironmentTestCase` deliberately does not register the middleware. Each test picks
  `lockWebGroup()` or `lockGlobally()`, so the `web` group requirement is proven rather than assumed.
- Any test that renders the password page needs `$this->withoutVite()`. The shipped Blade calls
  `@vite('resources/css/app.css')`, which throws without a host app manifest.
- Test observable behaviour through the public surface: service provider wiring, routes, config
  merge, published resources, translations, and the promises the README makes.

## Release Surface

The published archive is **12 files**. Everything dev-only is `export-ignore`d in `.gitattributes`
(`tests`, `.github`, the tool configs, `AGENTS.md`).

Add a new dev-only file at the root and it ships unless you add it there too. Verify with:

```bash
git archive --format=tar HEAD | tar -tf - | grep -v '/$'
```

`resources/boost/skills/statik-dev-lock-development/SKILL.md` **does** ship, and must keep valid
YAML frontmatter with `name` and `description` — Boost skips a malformed skill without warning, so
`tests/Feature/BoostSkillTest.php` asserts the contract. Update the skill when public behaviour,
config keys, publish tags, or README guidance change.

## Known Rough Edge

The shipped view calls `@vite('resources/css/app.css')`. In a host app with no built manifest this
throws — and because the lock gates every `web` request, the whole site returns 500 with no way in.
The documented workaround is publishing the view and dropping the `@vite` line. A fallback that
degrades to inline styles when no manifest or hot file exists has not been implemented.

## Conventions

- Reach for Laravel-native package APIs and the existing service provider shape before adding
  abstractions.
- Add only the files and dependencies the behaviour being implemented needs.
- Prefer explicit code over helper abstractions unless the extension point is real.
- Comment the *why* when a decision is non-obvious (a security property, a PHP or Laravel version
  constraint, a Larastan workaround). The existing comments set the bar.
