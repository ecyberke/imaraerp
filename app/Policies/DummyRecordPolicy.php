<?php

namespace App\Policies;

use App\Models\DummyRecord;
use App\Models\User;

/**
 * "No controller action ships without a corresponding Policy check" (kickoff
 * brief / execution_plan.md). The tenant match here is defense-in-depth —
 * DummyRecordController's route-model binding already resolves through
 * DummyRecord's TenantScope, so a cross-tenant ID 404s before this policy
 * ever runs. This still checks explicitly so a future code path that bypasses
 * the global scope (withoutGlobalScope, a queued job, ...) fails closed
 * rather than silently authorizing.
 */
class DummyRecordPolicy
{
    public function view(User $user, DummyRecord $dummyRecord): bool
    {
        return $user->tenant_id === $dummyRecord->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
