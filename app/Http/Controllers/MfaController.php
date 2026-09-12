<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

/**
 * Architecture §1.1: "MFA is mandatory for Finance and Admin roles from
 * Phase 1." Minimal TOTP enrollment - setup() issues a secret (not yet
 * active), confirm() verifies a code against it and flips mfa_enabled on.
 * Once enabled, EnsureMfaForSensitiveRoles (see bootstrap/app.php) blocks
 * Finance/Admin from sensitive actions until this has run.
 */
class MfaController extends Controller
{
    public function setup(Request $request)
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $user = $request->user();
        $user->mfa_secret = $secret;
        $user->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret,
            ),
        ]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->mfa_secret) {
            return response()->json(['message' => 'MFA setup has not been started.'], 422);
        }

        $google2fa = new Google2FA;

        if (! $google2fa->verifyKey($user->mfa_secret, $data['code'])) {
            return response()->json(['message' => 'Invalid MFA code.'], 422);
        }

        $user->mfa_enabled = true;
        $user->save();

        return response()->json(['mfa_enabled' => true]);
    }
}
