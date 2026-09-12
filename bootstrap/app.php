<?php

use App\Exceptions\NoOpenAccountingPeriodException;
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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum runs in pure bearer-token mode (execution_plan.md §Phase 0
        // project-init, architecture §1.1). Do NOT call ->statefulApi() here —
        // that switches Sanctum into SPA cookie-session mode and pulls in
        // CSRF-cookie semantics this project deliberately doesn't use.
        $middleware->alias([
            'mfa' => EnsureMfaForSensitiveRoles::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NoOpenAccountingPeriodException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
