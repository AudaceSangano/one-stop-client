<?php

namespace Maico\OneStop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Maico\OneStop\OneStop;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets MAICO One-Stop show the application inside its own page, and stops any other site from framing it.
 * Only applied while One-Stop sign-in is enabled; otherwise responses are left unchanged.
 */
class AllowOneStopEmbedding
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('one-stop.allow_embedding') && OneStop::enabled() && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' ".config('one-stop.url'));
        }

        return $response;
    }
}
