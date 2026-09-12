<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Architecture §1.1: "HTTPS/TLS everywhere, HSTS enabled." HSTS only makes
 * sense once TLS is actually terminated in front of the app (local dev
 * over plain HTTP has no certificate to pin), so it's gated on the
 * environment rather than sent unconditionally.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
