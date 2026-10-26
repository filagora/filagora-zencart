<?php

use Filagora\ZenCart\VersionCheck\VersionCheckController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * The version-check URLs deployed carts call. Public, GET, and no session: the answer is identical
 * for every caller and cacheable by shared caches, and a Set-Cookie would stop that.
 */
Route::middleware('web')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->group(function (): void {
        Route::get('/plugin_check.php/{legacyPath?}', VersionCheckController::class)
            ->where('legacyPath', '.*')
            ->middleware('throttle:legacy-plugin-check')
            ->name('filagora-zencart.versioncheck.legacy');

        Route::get('/plugincheck/{legacyPath}', VersionCheckController::class)
            ->where('legacyPath', '[0-9]+')
            ->middleware('throttle:legacy-plugin-check')
            ->name('filagora-zencart.versioncheck.short');
    });
