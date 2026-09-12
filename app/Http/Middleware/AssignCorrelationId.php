<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Architecture §1.1: "structured logging carries a correlation ID from
 * the initiating HTTP request through any queued job and webhook callback
 * it spawns, so one Payment's full lifecycle is traceable end to end."
 * This is the HTTP-request end of that chain - a queued job picks the
 * same ID up from whatever payload/context it's dispatched with (wired as
 * each job type is built, not generic middleware machinery).
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-Id') ?: (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        Log::shareContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
