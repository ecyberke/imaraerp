<?php

namespace App\Services;

use App\Models\ApprovalLimit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;

/**
 * §3.10. `admin` always passes outright (same universal-bypass convention
 * every other Policy in this codebase already uses) - every other role
 * needs a real ApprovalLimit row for the entity_type being approved, or
 * the approval is refused rather than silently allowed (fail-closed: "no
 * limit configured" means this role was never granted authority over
 * this entity_type, not that the check doesn't apply).
 */
class ApprovalLimitService
{
    public function limitFor(Tenant $tenant, string $entityType, int $roleId): ?ApprovalLimit
    {
        return ApprovalLimit::where('tenant_id', $tenant->id)
            ->where('entity_type', $entityType)
            ->where('role_id', $roleId)
            ->first();
    }

    /**
     * @return array{approved: bool, requires_second_approval: bool, second_approver_role_id: ?int, reason: ?string}
     */
    public function evaluate(Tenant $tenant, string $entityType, User $approver, Money $amount): array
    {
        if ($approver->role?->name === 'admin') {
            return ['approved' => true, 'requires_second_approval' => false, 'second_approver_role_id' => null, 'reason' => null];
        }

        $limit = $this->limitFor($tenant, $entityType, $approver->role_id);
        if (! $limit) {
            return [
                'approved' => false, 'requires_second_approval' => false, 'second_approver_role_id' => null,
                'reason' => "No ApprovalLimit is configured for your role against '{$entityType}'.",
            ];
        }

        if ($amount->greaterThan($limit->max_amount)) {
            return [
                'approved' => false, 'requires_second_approval' => false, 'second_approver_role_id' => null,
                'reason' => 'This amount exceeds your approval authority for this entity type.',
            ];
        }

        $requiresSecond = $limit->requires_second_approval_above
            && $amount->greaterThan($limit->requires_second_approval_above);

        return [
            'approved' => true,
            'requires_second_approval' => $requiresSecond,
            'second_approver_role_id' => $requiresSecond ? $limit->second_approver_role_id : null,
            'reason' => null,
        ];
    }

    /** §3.10: "a second approval must come from a different user, not just a different role held by the same person." */
    public function assertSecondApprover(User $firstApprover, User $secondApprover, ?int $requiredRoleId): void
    {
        if ($firstApprover->id === $secondApprover->id) {
            throw new \DomainException('The second approval must come from a different user than the first approver.');
        }

        if ($requiredRoleId !== null && $secondApprover->role_id !== $requiredRoleId) {
            throw new \DomainException('This second approval requires an approver with a specific role.');
        }
    }
}
