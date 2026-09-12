<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LoginController::class, 'login']);

Route::middleware('auth:sanctum')->get('/health', function (Request $request) {
    return response()->json([
        'ok' => true,
        'user_id' => $request->user()->id,
        'tenant_id' => $request->user()->tenant_id,
    ]);
});
