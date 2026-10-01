<?php

namespace Statikbe\DevLock\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Password protects environments that should not be publicly reachable (staging, acceptance, ...).
 *
 * The lock is switched on with the `dev-lock.dev_enabled` config flag, which also
 * registers the dev.lock routes. While it is on, every request has to pass the password
 * form first, except for:
 * - paths matching `dev-lock.dev_skip_patterns` (health check, API, ...)
 * - clients whose IP is listed in `dev-lock.dev_whitelist_ips`
 * - sessions that already entered the password (rate limited per IP)
 *
 * Register this on the `web` middleware group in the host app's bootstrap/app.php. It uses
 * `routeIs()`, which needs a resolved route, so global middleware (which runs before the
 * router dispatches) cannot work.
 */
class DevLockMiddleware
{
    private const string RATE_LIMIT_KEY_PREFIX = 'dev_lock_password_attempts:';

    /**
     * The number of password attempts allowed per IP before the lockout kicks in.
     */
    private const int MAX_PASSWORD_ATTEMPTS = 5;

    /**
     * How long failed attempts keep counting against an IP, in seconds (5 minutes).
     */
    private const int LOCKOUT_DURATION = 300;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if ($this->shouldSkipProtection($request)) {
            return $next($request);
        }

        // A lock without a password can only fail closed: no form, no whitelist, no session.
        if ($this->configuredPassword() === null) {
            return $this->showMisconfiguredNotice();
        }

        if ($this->isAuthenticated()) {
            // Nothing left to unlock, so move visitors off the password form.
            if ($request->routeIs('dev.lock')) {
                return redirect($this->getRedirectUrl($request));
            }

            return $next($request);
        }

        if ($this->isIpWhitelisted($request)) {
            return $next($request);
        }

        if ($this->isPasswordSubmission($request)) {
            return $this->handlePasswordSubmission($request);
        }

        if ($request->routeIs('dev.lock')) {
            return $this->showPasswordForm($request);
        }

        // Ask for the password first and remember where the visitor was heading.
        return redirect()->route('dev.lock', ['redirect_to' => $request->fullUrl()]);
    }

    /**
     * Determine if protection should be skipped.
     *
     * The dev.lock routes are only registered when `dev-lock.dev_enabled` is true
     * (see routes/dev-lock.php), so the same strict check is used here: without
     * those routes there is no password form to send anybody to.
     */
    private function shouldSkipProtection(Request $request): bool
    {
        if (config('dev-lock.dev_enabled') !== true) {
            return true;
        }

        foreach ((array) config('dev-lock.dev_skip_patterns', []) as $pattern) {
            if (is_string($pattern) && $request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured password, or null when the lock has not been given one.
     */
    private function configuredPassword(): ?string
    {
        $password = config('dev-lock.dev_password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    /**
     * Check if the visitor already entered the password.
     */
    private function isAuthenticated(): bool
    {
        return session('dev_lock_authenticated') === true;
    }

    /**
     * Check if the client IP is whitelisted.
     *
     * The config value is either an array or a comma separated string of IPs.
     */
    private function isIpWhitelisted(Request $request): bool
    {
        $allowedIps = config('dev-lock.dev_whitelist_ips', []);

        if (! is_array($allowedIps)) {
            $allowedIps = explode(',', (string) $allowedIps);
        }

        return in_array($request->ip(), $allowedIps, strict: true);
    }

    /**
     * Check if the current request is a password submission.
     */
    private function isPasswordSubmission(Request $request): bool
    {
        return $request->isMethod('POST')
            && $request->routeIs('dev.lock.submit')
            && $request->has('dev_lock_password');
    }

    /**
     * Validate a password submission and let the visitor in when it is correct.
     */
    private function handlePasswordSubmission(Request $request): RedirectResponse
    {
        $rateLimitKey = self::RATE_LIMIT_KEY_PREFIX.$request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_PASSWORD_ATTEMPTS)) {
            return $this->rejectPasswordAttempt($request, __('auth.throttle', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
            ]));
        }

        if (! $this->isValidPassword($request->input('dev_lock_password'))) {
            RateLimiter::hit($rateLimitKey, self::LOCKOUT_DURATION);

            return $this->rejectPasswordAttempt($request, __('validation.current_password'));
        }

        RateLimiter::clear($rateLimitKey);

        // Regenerate before granting access, so a session id an attacker planted
        // beforehand (a sibling subdomain is enough) does not become an unlocked one.
        $request->session()->regenerate();

        session(['dev_lock_authenticated' => true]);

        return redirect($this->getRedirectUrl($request));
    }

    /**
     * Check if the submitted password matches the configured one, in constant time.
     *
     * Anything that is not a string is rejected outright: `dev_lock_password[]=x`
     * submits an array, which would be a TypeError inside hash_equals().
     */
    private function isValidPassword(mixed $submitted): bool
    {
        $expected = $this->configuredPassword();

        if ($expected === null || ! is_string($submitted)) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * Send the visitor back to the password form with an error, keeping their destination.
     */
    private function rejectPasswordAttempt(Request $request, string $error): RedirectResponse
    {
        session()->flash('dev_lock_error', $error);

        return redirect()->route('dev.lock', ['redirect_to' => $request->query('redirect_to')]);
    }

    /**
     * Show the password form.
     */
    private function showPasswordForm(Request $request): Response
    {
        return $this->withLockHeaders(response()->view('dev-lock::dev-lock', [
            'error' => session('dev_lock_error'),
            'redirect_to' => $request->query('redirect_to'),
            'styles' => $this->styles(),
            'username' => $this->configuredUsername(),
        ], 401));
    }

    /**
     * The value for the form's hidden username field.
     *
     * A password manager keys a saved login on a username and will not treat a form
     * without one as a sign-in, so the form carries one even though the lock has no
     * accounts. It is never read back on submit: it decides nothing, it only has to
     * stay stable or entries saved against it stop matching.
     */
    private function configuredUsername(): string
    {
        $username = config('dev-lock.dev_username');

        return is_string($username) ? $username : '';
    }

    /**
     * The stylesheet markup for the password page.
     *
     * Normally the host app's compiled CSS, but resolving that can fail in ways which are entirely
     * normal for a locked environment: an app with no Vite build at all, a deploy that never ran the
     * frontend build (no manifest), or a CSS entrypoint other than the configured one (a manifest
     * without it). This middleware gates every request, so any of those would take the whole
     * environment down with no way back in — hence the fallback, and hence resolving the tags here
     * instead of with `@vite` in the view.
     *
     * `Throwable` rather than `ViteException`: the failures above are separate exception classes,
     * and this wraps a single call, so there is nothing else it can swallow.
     */
    private function styles(): HtmlString
    {
        $entrypoint = config('dev-lock.dev_vite_entrypoint');

        if (! is_string($entrypoint) || $entrypoint === '') {
            return $this->fallbackStyles();
        }

        try {
            return app(Vite::class)($entrypoint);
        } catch (Throwable) {
            return $this->fallbackStyles();
        }
    }

    /**
     * The package's own stylesheet, inlined.
     *
     * Inlined rather than linked because a file under the package's `resources/` is not web
     * accessible: linking it would need publishing into the host app's public directory, and a
     * fallback that only works after a publish step is not a fallback.
     */
    private function fallbackStyles(): HtmlString
    {
        return new HtmlString('<style>'.file_get_contents($this->fallbackStylesheet()).'</style>');
    }

    /**
     * The fallback stylesheet to inline, preferring a copy published with the
     * `dev-lock-css` tag over the packaged one — as published views work.
     */
    private function fallbackStylesheet(): string
    {
        $published = resource_path('css/vendor/dev-lock/dev-lock.css');

        return is_file($published) ? $published : __DIR__.'/../../../resources/css/dev-lock.css';
    }

    /**
     * Tell whoever is looking at this environment that the lock has no password.
     *
     * Deliberately loud: a form that rejects every correct password costs far more to
     * debug than a response naming the env variable that is missing.
     */
    private function showMisconfiguredNotice(): Response
    {
        return $this->withLockHeaders(response(__('dev-lock::messages.dev_lock.not_configured'), 503));
    }

    /**
     * Keep lock responses out of caches, frames and search indexes.
     */
    private function withLockHeaders(Response $response): Response
    {
        return $response
            ->header('X-Frame-Options', 'DENY') // Prevent clickjacking
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0') // Prevent caching
            ->header('X-Robots-Tag', 'noindex, nofollow'); // Prevent search engines
    }

    /**
     * Get the URL to redirect to after successful authentication.
     *
     * Only URLs on this application's own host are accepted. FILTER_VALIDATE_URL
     * alone is not enough: it happily validates https://evil.example, which would
     * let ?redirect_to= bounce a visitor off-site straight from our own domain.
     */
    private function getRedirectUrl(Request $request): string
    {
        $redirectUrl = $request->query('redirect_to');

        if (! is_string($redirectUrl) || $redirectUrl === '') {
            return '/';
        }

        return $this->isSameHost($request, $redirectUrl) ? $redirectUrl : '/';
    }

    /**
     * Is this URL on the application's own host?
     *
     * To avoid redirecting to third party domains (e.g. used in phishing), the
     * host of the URL has to match one of ours exactly. The redirect target is
     * generated from the incoming request, so the request host is the
     * authoritative comparison. app.url is accepted as well, since it is not
     * always kept in sync with the host actually being served.
     */
    private function isSameHost(Request $request, string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // FILTER_VALIDATE_URL accepts any scheme, including javascript: and data:.
        // Browsers ignore those in a Location header, but there is no reason to emit one.
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], strict: true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $allowedHosts = array_map(mb_strtolower(...), array_filter([
            $request->getHost(),
            parse_url((string) config('app.url'), PHP_URL_HOST),
        ]));

        return in_array(mb_strtolower($host), $allowedHosts, strict: true);
    }
}
