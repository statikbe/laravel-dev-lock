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
config/statik-dev-lock.php                    5 keys, all env-driven except dev_skip_patterns
routes/statik-dev-lock.php                    dev.lock, dev.lock.submit — registered only when enabled
resources/views/dev-lock.blade.php            the password page
resources/css/dev-lock.css                    fallback styles, inlined when Vite cannot resolve
lang/{en,nl}/messages.php                     translations
resources/boost/skills/.../SKILL.md           bundled Laravel Boost skill (ships to consumers)
```

Namespaces: `Statikbe\StatikDevLock\` → `src/`, `Statikbe\StatikDevLock\Tests\` → `tests/`.

Config namespace is `statik-dev-lock`; env vars are prefixed `STATIK_DEV_LOCK_`. Publish tags are
`statik-dev-lock` plus `-config`, `-views`, `-lang`, `-css`.

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
- Rendering the password page needs no `$this->withoutVite()`. Testbench has no Vite manifest, so
  those tests exercise the real fallback — which is the point, and why the calls were removed.
- Test observable behaviour through the public surface: service provider wiring, routes, config
  merge, published resources, translations, and the promises the README makes.

## Release Surface

The published archive is **13 files**. Everything dev-only is `export-ignore`d in `.gitattributes`
(`tests`, `.github`, the tool configs, `AGENTS.md`).

Add a new dev-only file at the root and it ships unless you add it there too. Verify with:

```bash
git archive --format=tar HEAD | tar -tf - | grep -v '/$'
```

`resources/boost/skills/statik-dev-lock-development/SKILL.md` **does** ship, and must keep valid
YAML frontmatter with `name` and `description` — Boost skips a malformed skill without warning, so
`tests/Feature/BoostSkillTest.php` asserts the contract. Update the skill when public behaviour,
config keys, publish tags, or README guidance change.

## Styling And The Vite Fallback

The page is styled with the host app's Tailwind build, but the view does **not** call `@vite`. The
middleware resolves the tags in `styles()` and passes them in, because `@vite` throws in two
situations that are normal for a locked environment — a deploy that skipped the frontend build has
no manifest, and an app whose CSS entry is not `resources/css/app.css` has a manifest without it.
Since the lock gates every `web` request, either one would 500 the whole environment with no way
back in.

The entrypoint is `dev_vite_entrypoint` (default `resources/css/app.css`); set it to null or an empty
string and Vite is skipped entirely. Either way `styles()` returns an `HtmlString`, so the view is
just `{!! $styles !!}` — no conditional in the template.

The fallback inlines `resources/css/dev-lock.css`, and the Tailwind classes in the markup go inert.
That stylesheet works from element selectors plus `#dev-lock-card`, `#dev-access-heading` and
`#dev-lock-note`, so **keep those ids on the markup** — they are the fallback's only hooks. A copy
published with `statik-dev-lock-css` to `resources/css/vendor/statik-dev-lock/dev-lock.css` wins over
the packaged one, the way published views do. It is inlined rather than linked because a file under
the package's `resources/` is not web accessible without publishing into the host app's `public/`.

`tests/Middleware/DevLockViteFallbackTest.php` covers all four paths: no manifest, a resolvable
build, a Vite failure that is not a missing manifest, and a stubbed-out Vite.

## Conventions

- Reach for Laravel-native package APIs and the existing service provider shape before adding
  abstractions.
- Add only the files and dependencies the behaviour being implemented needs.
- Prefer explicit code over helper abstractions unless the extension point is real.
- Comment the *why* when a decision is non-obvious (a security property, a PHP or Laravel version
  constraint, a Larastan workaround). The existing comments set the bar.
