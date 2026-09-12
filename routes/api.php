<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DummyRecordController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MfaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rate-limited auth endpoints (architecture §1.1's security baseline) -
// both unauthenticated by design (login has no session yet; accepting an
// invitation is how the account it would log into first gets created).
Route::post('/auth/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:5,1');

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
