<?php

use App\Exceptions\NoOpenAccountingPeriodException;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureIdempotent;
use App\Http\Middleware\EnsureMfaForSensitiveRoles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Laravel's built-in liveness probe - this IS what architecture
        // §1.1's "/health (liveness)" baseline means, just at Laravel's own
        // default path rather than literally "/health" (which this app
        // separately uses, under auth:sanctum, as a "who am I" diagnostic
        // route for tests - two different things sharing a similar name,
        // worth the naming collision being explicit rather than confused
        // for one another later). "/ready" (readiness: DB + queue
        // connectivity) is a real route in routes/api.php, unauthenticated
        // like this one has to be - an infra probe can't hold a bearer token.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum runs in pure bearer-token mode (execution_plan.md §Phase 0
        // project-init, architecture §1.1). Do NOT call ->statefulApi() here —
        // that switches Sanctum into SPA cookie-session mode and pulls in
        // CSRF-cookie semantics this project deliberately doesn't use.
        $middleware->alias([
            'mfa' => EnsureMfaForSensitiveRoles::class,
            'idempotent' => EnsureIdempotent::class,
        ]);

        // Structured logging with a correlation ID (§1.1), on every request.
        $middleware->api(prepend: [AssignCorrelationId::class]);
        $middleware->append(AddSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NoOpenAccountingPeriodException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
