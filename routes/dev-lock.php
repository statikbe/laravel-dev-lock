<?php

use Illuminate\Support\Facades\Route;

// The `web` group is what gives these routes a session, CSRF protection and the
// DevLockMiddleware the host app registered on that group. The middleware answers
// both routes, so the actions below only run when it is not registered at all; sending
// visitors home is the safe fallback for that case.
if (config('dev-lock.dev_enabled') === true) {
    Route::middleware('web')->name('dev.')->group(function (): void {
        Route::get('/__dev-lock', fn () => redirect('/'))
            ->name('lock');

        Route::post('/__dev-lock', fn () => redirect('/'))
            ->name('lock.submit');
    });
}
