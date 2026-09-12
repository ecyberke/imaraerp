<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Architecture §1.1: "all Sanctum tokens revoked immediately on password
 * change or account termination - not just a status flag flipped while
 * old tokens keep working." This is the password-change half; account
 * termination is deferred until a real user-lifecycle/offboarding action
 * exists to trigger it (not built anywhere yet - not a gap specific to
 * this branch, there's no User CRUD at all yet beyond invitation
 * acceptance).
 */
class AccountController extends Controller
{
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();

        // Revoke every other token immediately - the current request's own
        // token is the one exception, so the session that just changed the
        // password keeps working without forcing an immediate re-login.
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }
}
