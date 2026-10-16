<?php

namespace Maico\OneStop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maico\OneStop\OneStop;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session that was started with One-Stop after One-Stop reports the person signed out
 * there (or was suspended). Sessions started with the application's own password are never affected.
 */
class EndSignedOutOneStopSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $signOutsAtSignIn = $request->hasSession() ? $request->session()->get(OneStop::SIGN_OUTS_AT_SIGN_IN) : null;
        $user = $request->user();

        if ($signOutsAtSignIn === null || ! $user || (int) $user->getAttribute('one_stop_sign_outs') <= $signOutsAtSignIn) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'You signed out of One-Stop.', 'redirect' => OneStop::routeUrl('login_route')], 401);
        }

        return redirect()->to(OneStop::routeUrl('login_route'))->with('status', 'You signed out of One-Stop, so you were signed out here too.');
    }
}
