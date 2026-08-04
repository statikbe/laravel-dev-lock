# Release Notes

## [v0.0.1](https://github.com/statikbe/laravel-dev-lock/releases/tag/v0.0.1) - 2026-08-04

First pre-release. Password protects environments that should not be publicly reachable
(staging, acceptance, client preview) without an `.htaccess` file or server level basic auth.

### Added

- `Statikbe\DevLock\Http\Middleware\DevLockMiddleware`, the package's entire public surface.
  Register it on the `web` middleware group; it gates every request behind a password form until
  the visitor unlocks the environment.
- `config/dev-lock.php` with five keys, four of them env driven: `dev_enabled`
  (`DEV_LOCK_ENABLED`), `dev_password` (`DEV_LOCK_PASSWORD`), `dev_whitelist_ips`
  (`DEV_LOCK_WHITELIST_IPS`, defaults to `127.0.0.1` and `localhost`), `dev_skip_patterns`
  (defaults to `up` and `api/*`, matched with `Request::is()`, config only) and
  `dev_vite_entrypoint` (`DEV_LOCK_VITE_ENTRYPOINT`, defaults to `resources/css/app.css`).
- Routes `dev.lock` (GET `/__dev-lock`) and `dev.lock.submit` (POST), registered in the `web`
  group and only while the lock is enabled.
- A Tailwind password page, `dev-lock::dev-lock`, and English and Dutch translations.
- Publish tags `dev-lock`, `dev-lock-config`, `dev-lock-views`, `dev-lock-lang` and
  `dev-lock-css`. A stylesheet published to `resources/css/vendor/dev-lock/dev-lock.css` takes
  precedence over the packaged one.
- A bundled Laravel Boost skill, `dev-lock-development`, that walks a consuming app through
  adoption and through replacing its own hand-rolled dev lock with this package.

### Behaviour worth knowing

- The password page never depends on the host app having a built Vite manifest. The middleware
  resolves the compiled CSS itself and inlines `resources/css/dev-lock.css` when it cannot, so an
  app with no build, an unbuilt deploy or a missing manifest entry still gets a form instead of a
  `500` on every request. Set `dev_vite_entrypoint` to `null` to skip Vite altogether.
- The page markup carries `dev-lock-card`, `dev-access-heading` and `dev-lock-note` ids. The
  fallback stylesheet styles the page through them, since the Tailwind classes are inert without a
  build — keep them on a published view.
- Failed password attempts are rate limited per IP: 5 attempts, then a 5 minute lockout.
- Unlocking regenerates the session id before storing `dev_lock_authenticated`, so a session id
  planted beforehand cannot become an unlocked one.
- `?redirect_to=` is only honoured for `http`/`https` URLs on the application's own host;
  anything else lands on `/`.
- The password form answers `401` with `X-Frame-Options: DENY`,
  `X-Robots-Tag: noindex, nofollow` and a `no-store` `Cache-Control`.
- Enabling the lock without `DEV_LOCK_PASSWORD` fails closed: protected requests get a `503`
  naming the missing variable instead of a form nothing can pass.
- The lock hides an environment. It is not authentication and does not protect user data.

### Requirements

- PHP 8.3 or higher
- Laravel 12 or 13 (`illuminate/support`)

CI verifies PHP 8.3, 8.4 and 8.5 against Laravel 12 and 13, on Linux and Windows, at both
`prefer-lowest` and `prefer-stable` — the matrix is exactly the package's Composer constraints.
Laravel 10 and 11 are deliberately out of scope: both branches are past their security-support
window and Composer refuses to install them without disabling its advisory policy. See the
README's requirements table.
