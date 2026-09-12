<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Architecture §1.1: "MFA is mandatory for Finance and Admin roles from
 * Phase 1, optional elsewhere - those two roles touch retention releases,
 * credit approvals, and payroll." Applied per-route (see routes/api.php)
 * to every action that touches a financial entity - dummy-records today,
 * every real financial-entity route from ledger-core onward. A Finance/
 * Admin user who hasn't completed MFA enrollment (POST /api/mfa/setup +
 * /api/mfa/confirm) is blocked here, not silently allowed through.
 */
class EnsureMfaForSensitiveRoles
{
    private const MFA_REQUIRED_ROLES = ['finance', 'admin'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array($user->role?->name, self::MFA_REQUIRED_ROLES, true) && ! $user->mfa_enabled) {
            return response()->json([
                'message' => 'MFA is required for your role before performing this action.',
                'mfa_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
