<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;

/**
 * §3.10's default channel policy, implemented as data rather than
 * scattered conditionals: "in-app only for internal workflow status
 * changes; email for financial/management notifications (approval
 * pending, milestone due); SMS reserved for field-facing, time-critical
 * alerts (QC failure, reorder trigger)." Digest-eligible types default to
 * batched_daily; "genuinely urgent types ... always stay immediate
 * regardless of the recipient's digest preference" - this codebase
 * doesn't yet store a per-recipient digest preference to override
 * (flagged, not built - §3.10 calls it "configurable per notification
 * type," which the per-type TYPE_CONFIG below is; per-user override is a
 * further step nothing here needed yet), so every digest-eligible type's
 * default_delivery_mode is what actually governs it.
 */
class NotificationService
{
    private const TYPE_CONFIG = [
        'reorder_alert' => ['channel' => 'sms', 'default_delivery_mode' => 'batched_daily'],
        'approval_pending' => ['channel' => 'email', 'default_delivery_mode' => 'immediate'],
        'qc_failure' => ['channel' => 'sms', 'default_delivery_mode' => 'immediate'],
        'milestone_due' => ['channel' => 'email', 'default_delivery_mode' => 'immediate'],
        'retention_release_due' => ['channel' => 'email', 'default_delivery_mode' => 'immediate'],
        'casual_conversion_due' => ['channel' => 'in_app', 'default_delivery_mode' => 'immediate'],
        'dlp_ready_to_close' => ['channel' => 'email', 'default_delivery_mode' => 'immediate'],
        'bom_variance_exceeded' => ['channel' => 'in_app', 'default_delivery_mode' => 'batched_daily'],
        'hire_invoice_mismatch' => ['channel' => 'email', 'default_delivery_mode' => 'immediate'],
    ];

    /** §3.10: these stay immediate no matter what - "QC failure blocking a certification, retention release ready." */
    private const ALWAYS_IMMEDIATE_TYPES = ['qc_failure', 'retention_release_due'];

    public function notify(User $user, string $type, string $message, ?string $entityType = null, ?int $entityId = null): Notification
    {
        if (! array_key_exists($type, self::TYPE_CONFIG)) {
            throw new \InvalidArgumentException("Unknown Notification type '{$type}'.");
        }

        $config = self::TYPE_CONFIG[$type];
        $deliveryMode = in_array($type, self::ALWAYS_IMMEDIATE_TYPES, true) ? 'immediate' : $config['default_delivery_mode'];

        return Notification::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'message' => $message,
            'channel' => $config['channel'],
            'delivery_mode' => $deliveryMode,
            'sent_at' => $deliveryMode === 'immediate' ? now() : null,
        ]);
    }

    /**
     * Several producers need "whoever holds this role," not one specific
     * User - dlp_ready_to_close routes to Project Manager by default
     * (§3.10's own example), the same pattern applies to every other
     * role-addressed type below.
     *
     * @return Notification[]
     */
    public function notifyRole(Tenant $tenant, string $roleName, string $type, string $message, ?string $entityType = null, ?int $entityId = null): array
    {
        $users = User::where('tenant_id', $tenant->id)
            ->whereHas('role', fn ($q) => $q->where('name', $roleName))
            ->get();

        return $users->map(fn (User $user) => $this->notify($user, $type, $message, $entityType, $entityId))->all();
    }

    /**
     * §3.10: fired when ApprovalLimitService::evaluate() determines a
     * second approval is required but the caller didn't supply one yet -
     * a simplification flagged rather than built out as a full two-step
     * "first approval recorded, awaiting second" workflow (VariationOrderService/
     * PurchaseOrderService/CreditApprovalService's approve()/override()
     * are each a single atomic call, not two separate persisted steps),
     * but it does get the right role notified that something needs
     * their action. $roleId comes straight from an ApprovalLimit row
     * (second_approver_role_id), resolved to a role name here since
     * notifyRole() addresses roles by name.
     */
    public function notifyApprovalPendingForRole(Tenant $tenant, ?int $roleId, string $entityType, int $entityId, string $message): void
    {
        $roleName = $roleId ? Role::withoutGlobalScopes()->find($roleId)?->name : null;
        if ($roleName) {
            $this->notifyRole($tenant, $roleName, 'approval_pending', $message, $entityType, $entityId);
        }
    }

    public function markRead(Notification $notification): Notification
    {
        $notification->update(['read_at' => now()]);

        return $notification->fresh();
    }

    /**
     * §3.10: "one summary message instead of twenty separate ones."
     * Dispatches every still-unsent digest-eligible Notification whose
     * delivery_mode window has elapsed - daily types always qualify when
     * this runs daily; weekly types only once 7 days have passed since
     * their creation, so a Monday-created weekly notification doesn't
     * go out the next day's run.
     *
     * Dispatch itself is simulated by setting sent_at (actually
     * delivering over email/SMS is Phase 3 integration work, same
     * "fields/mechanism exist now, the external API call comes later"
     * relationship as ETR fields existing on Invoice before TIMS
     * submission ships).
     *
     * @return int the number of notifications marked sent
     */
    public function dispatchDueDigests(): int
    {
        $dueDaily = Notification::withoutGlobalScopes()
            ->whereNull('sent_at')
            ->where('delivery_mode', 'batched_daily')
            ->get();

        $dueWeekly = Notification::withoutGlobalScopes()
            ->whereNull('sent_at')
            ->where('delivery_mode', 'batched_weekly')
            ->where('created_at', '<=', now()->subDays(7))
            ->get();

        $due = $dueDaily->merge($dueWeekly);
        $due->each(fn (Notification $n) => $n->update(['sent_at' => now()]));

        return $due->count();
    }
}
