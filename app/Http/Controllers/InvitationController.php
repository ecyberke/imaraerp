<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Architecture §3.1: "the invitee follows a tokenized link to set a
 * password and activate their User row, rather than an admin creating
 * accounts and distributing passwords directly." Unauthenticated by
 * design - possession of the token IS the authorization, the same pattern
 * as a password-reset link.
 */
class InvitationController extends Controller
{
    public function accept(Request $request, string $token)
    {
        $invitation = UserInvitation::where('token', $token)->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->accepted_at !== null) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 422);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json(['message' => 'This invitation has expired.'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Password policy favoring length over complexity rules (§1.1).
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create([
            'tenant_id' => $invitation->tenant_id,
            'name' => $data['name'],
            'email' => $invitation->email,
            'role_id' => $invitation->role_id,
            'password' => $data['password'],
            'mfa_enabled' => false,
        ]);

        $invitation->accepted_at = now();
        $invitation->save();

        $tokenValue = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $tokenValue,
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role?->name,
            ],
        ], 201);
    }
}
