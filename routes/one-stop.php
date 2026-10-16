<?php

use Illuminate\Support\Facades\Route;
use Maico\OneStop\Http\Controllers\OneStopController;

// The CSRF middleware's name differs between Laravel versions; exclude whichever exist.
$csrf = array_values(array_filter([
    'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
    'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
    'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
], 'class_exists'));

Route::prefix(config('one-stop.routes.prefix'))
    ->name(config('one-stop.routes.name'))
    ->middleware(config('one-stop.routes.middleware'))
    ->group(function () use ($csrf) {
        Route::get('/redirect', [OneStopController::class, 'redirect'])->middleware('throttle:20,1')->name('redirect');
        Route::get('/callback', [OneStopController::class, 'callback'])->middleware('throttle:20,1')->name('callback');
        // Server-to-server notice from One-Stop, authenticated by its HMAC signature instead of a CSRF token.
        Route::post('/sign-out', [OneStopController::class, 'signOut'])->withoutMiddleware($csrf)->middleware('throttle:60,1')->name('sign-out');
    });
