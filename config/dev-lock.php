<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dev Lock Enabled
    |--------------------------------------------------------------------------
    |
    | Switches the lock on. While it is off the middleware passes every request
    | through and the dev.lock routes are not registered at all.
    |
    */

    'dev_enabled' => env('DEV_LOCK_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Dev Lock Password
    |--------------------------------------------------------------------------
    |
    | The password visitors have to enter. There is deliberately no default: a
    | password shipped in package source is a password everybody knows. When the
    | lock is enabled without one, protected requests are denied with a notice
    | instead of a form.
    |
    */

    'dev_password' => env('DEV_LOCK_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Whitelisted IPs
    |--------------------------------------------------------------------------
    |
    | Clients on these IPs never see the password form. Either an array or a
    | comma separated string, e.g. DEV_LOCK_WHITELIST_IPS="1.2.3.4,5.6.7.8".
    |
    */

    'dev_whitelist_ips' => env('DEV_LOCK_WHITELIST_IPS', [
        '127.0.0.1',
        'localhost',
    ]),

    /*
    |--------------------------------------------------------------------------
    | Skipped Paths
    |--------------------------------------------------------------------------
    |
    | Request paths that stay reachable while the lock is on, matched with
    | Request::is(), so wildcards are supported.
    |
    */

    'dev_skip_patterns' => [
        'up',
        'api/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vite Entrypoint
    |--------------------------------------------------------------------------
    |
    | The compiled CSS used to style the password page. Point this at the
    | entrypoint this app actually builds, or set it to null to skip Vite
    | altogether and always use the package's own stylesheet.
    |
    | Either way the page renders: when the entrypoint cannot be resolved,
    | resources/css/dev-lock.css is inlined instead.
    |
    */

    'dev_vite_entrypoint' => env('DEV_LOCK_VITE_ENTRYPOINT', 'resources/css/app.css'),

];
