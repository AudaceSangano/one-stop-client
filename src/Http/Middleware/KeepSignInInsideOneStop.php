<?php

namespace Maico\OneStop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Maico\OneStop\OneStop;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside One-Stop, people sign in with One-Stop only: a request for the application's own sign-in
 * page from One-Stop's frame is sent to "Sign in with MAICO One-Stop" instead, so the password form
 * never appears there. Opened directly in the browser, the sign-in page is unchanged.
 *
 * Turn off with 'password_sign_in_inside_one_stop' => true in config/one-stop.php.
 */
class KeepSignInInsideOneStop
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('one-stop.password_sign_in_inside_one_stop')
            && OneStop::enabled()
            && $request->isMethod('GET')
            && $request->header('Sec-Fetch-Dest') === 'iframe'
            && $request->routeIs((string) config('one-stop.login_route'))
            && ! $request->user()) {
            return redirect()->route(config('one-stop.routes.name').'redirect', ['embedded' => 1]);
        }

        return $next($request);
    }
}
