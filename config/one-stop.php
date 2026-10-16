<?php

/*
|--------------------------------------------------------------------------
| Sign in with MAICO One-Stop
|--------------------------------------------------------------------------
|
| An optional second way in. While disabled (or not fully configured) the
| application behaves exactly as without this package: its routes return 404
| and no headers or middleware change anything. Password sign-in, users,
| roles and permissions always stay with the application.
|
*/

return [

    'enabled' => (bool) env('ONE_STOP_ENABLED', false),

    // Where One-Stop runs, e.g. https://onestop.maico.co.rw (no trailing slash).
    'url' => rtrim((string) env('ONE_STOP_URL', ''), '/'),

    // Shown once by One-Stop when the application is registered for single sign-on.
    'client_id' => env('ONE_STOP_CLIENT_ID'),
    'client_secret' => env('ONE_STOP_CLIENT_SECRET'),

    // Seconds to wait for One-Stop before falling back to password sign-in.
    'timeout' => (int) env('ONE_STOP_TIMEOUT', 5),

    // The application's name in messages, e.g. "No StockFlow account uses …".
    'app_name' => env('ONE_STOP_APP_NAME', env('APP_NAME', 'this application')),

    'users' => [
        // The Eloquent model people sign in as. Needs the one_stop_id and one_stop_sign_outs columns.
        'model' => 'App\\Models\\User',
        // Updated on each One-Stop sign-in; set to null if the table has no such column.
        'last_login_column' => 'last_login_at',
    ],

    'routes' => [
        // Gives /auth/one-stop/redirect, /auth/one-stop/callback and /auth/one-stop/sign-out.
        'prefix' => 'auth/one-stop',
        'name' => 'one-stop.',
        'middleware' => ['web'],
    ],

    // Named routes the package sends people to.
    'login_route' => 'login',
    'home_route' => 'home',

    // Let One-Stop show the application inside its own page (and stop other sites from framing it).
    'allow_embedding' => true,

    // Inside One-Stop, send requests for the sign-in page to "Sign in with MAICO One-Stop" so the
    // password form never shows there. Opened directly, the sign-in page is unchanged.
    'password_sign_in_inside_one_stop' => false,

];
