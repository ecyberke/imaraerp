<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DummyRecordController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MfaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Rate-limited auth endpoints (architecture §1.1's security baseline) -
// both unauthenticated by design (login has no session yet; accepting an
// invitation is how the account it would log into first gets created).
// This IP-keyed throttle guards against raw request flooding; it's
// deliberately looser than LoginController's own email-keyed account
// lockout (5 failed attempts / 15 min), which is the actual brute-force
// defense and works regardless of how many IPs an attacker spreads
// across - the two are complementary, not duplicates.
Route::post('/auth/login', [LoginController::class, 'login'])->middleware('throttle:30,1');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:30,1');

// Readiness probe (§1.1): DB + queue connectivity. Unauthenticated, like
// Laravel's own /up liveness route - an infra probe can't hold a bearer
// token. The 'database' queue driver shares the DB connection this
// checks, so a separate queue check would be redundant at Phase 1; that
// changes if/when the queue connection moves off 'database'.
Route::get('/ready', function () {
    try {
        DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Throwable) {
        $dbOk = false;
    }

    return response()->json(['ready' => $dbOk, 'db' => $dbOk], $dbOk ? 200 : 503);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/health', function (Request $request) {
        return response()->json([
            'ok' => true,
            'user_id' => $request->user()->id,
            'tenant_id' => $request->user()->tenant_id,
        ]);
    });

    Route::post('/mfa/setup', [MfaController::class, 'setup']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm']);

    // 'mfa' gates creation for Finance/Admin roles without MFA enrolled
    // (§1.1) - the pattern every real financial-entity mutation route
    // reuses from ledger-core onward.
    Route::post('/dummy-records', [DummyRecordController::class, 'store'])->middleware('mfa');
    Route::get('/dummy-records/{dummyRecord}', [DummyRecordController::class, 'show']);
});
