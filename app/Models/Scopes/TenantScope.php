<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * Guards against self-referential recursion: resolving the Sanctum
     * guard's user looks up the User model, which re-applies this same
     * scope to build ITS query, which asks the guard for the user again,
     * ... infinite recursion (and a memory-exhaustion crash, not a stack
     * overflow, since each frame allocates before recursing) without this.
     */
    private static bool $resolvingGuard = false;

    /**
     * Resolve against the 'sanctum' guard explicitly, not the bare auth()
     * helper. Sanctum runs in pure bearer-token mode here (execution_plan.md
     * / architecture §1.1) and is registered under the guard name 'sanctum',
     * not the app's default guard ('web', session-based, and never actually
     * used by this API-only app). auth()->check()/auth()->user() resolve the
     * *default* guard — under a real bearer-token request that is always
     * false, so this scope would silently no-op on every live request if it
     * used the bare helper. Caught by the platform-foundation HTTP-level
     * tenant-isolation test, which a query-level test cannot catch.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (self::$resolvingGuard) {
            return;
        }

        self::$resolvingGuard = true;

        try {
            $user = Auth::guard('sanctum')->user();
        } finally {
            self::$resolvingGuard = false;
        }

        if ($user) {
            $builder->where($model->getTable().'.tenant_id', $user->tenant_id);
        }
    }
}
