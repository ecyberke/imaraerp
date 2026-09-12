<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController
{
    /**
     * Account lockout after repeated failures (§1.1), keyed by the
     * attempted email rather than IP - throttle:5,1 on the route already
     * limits raw request rate; this specifically limits guesses against
     * one account regardless of which IP they come from.
     */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900; // 15 minutes

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $lockoutKey = 'login-lockout:'.Str::lower($credentials['email']);

        if (RateLimiter::tooManyAttempts($lockoutKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($lockoutKey);

            throw ValidationException::withMessages([
                'email' => ["Too many failed attempts. Try again in {$seconds} seconds."],
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($lockoutKey, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($lockoutKey);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role?->name,
            ],
        ]);
    }
}
