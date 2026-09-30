<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\ResourceAssignment;
use App\Models\Tenant;
use App\Support\BusinessTime;

/**
 * §3.6: "scheduled → active fires automatically when block_start_date
 * is reached ... active → completed fires the same way against
 * block_end_date ... Either transition accepts a manual override."
 * The automatic half runs via the resource-assignments:advance-status
 * console command (Schedule::command(...)->daily()->withoutOverlapping(),
 * same pattern as invitations:prune-expired) - see routes/console.php.
 */
class ResourceAssignmentService
{
    public function create(
        Tenant $tenant,
        Resource $resource,
        ?int $projectId,
        ?int $salesOrderId,
        string $blockStartDate,
        string $blockEndDate,
    ): ResourceAssignment {
        if (($projectId === null) === ($salesOrderId === null)) {
            throw new \InvalidArgumentException('A ResourceAssignment must reference exactly one of project_id or sales_order_id.');
        }

        $assignment = ResourceAssignment::create([
            'tenant_id' => $tenant->id,
            'resource_id' => $resource->id,
            'project_id' => $projectId,
            'sales_order_id' => $salesOrderId,
            'block_start_date' => $blockStartDate,
            'block_end_date' => $blockEndDate,
            'status' => 'scheduled',
        ]);

        // A block that already starts today or earlier (a backfilled or
        // same-day assignment) shouldn't sit in 'scheduled' waiting for
        // tomorrow's job run - evaluate it immediately against today.
        $this->evaluateTransition($assignment);

        return $assignment->fresh();
    }

    /**
     * The automatic date-driven scan - tenant-agnostic (a system job,
     * not a per-tenant request), so it deliberately queries across every
     * tenant via withoutGlobalScopes() rather than being called once per
     * tenant.
     */
    public function advanceScheduledTransitions(): int
    {
        $today = BusinessTime::today()->toDateString();
        $advanced = 0;

        ResourceAssignment::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereDate('block_start_date', '<=', $today)
            ->each(function (ResourceAssignment $a) use (&$advanced) {
                $a->update(['status' => 'active']);
                $advanced++;
            });

        ResourceAssignment::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereDate('block_end_date', '<=', $today)
            ->each(function (ResourceAssignment $a) use (&$advanced) {
                $a->update(['status' => 'completed']);
                $advanced++;
            });

        return $advanced;
    }

    private function evaluateTransition(ResourceAssignment $assignment): void
    {
        $today = BusinessTime::today()->toDateString();

        if ($assignment->status === 'scheduled' && $assignment->block_start_date->toDateString() <= $today) {
            $assignment->update(['status' => 'active']);
        }

        if ($assignment->status === 'active' && $assignment->block_end_date->toDateString() <= $today) {
            $assignment->update(['status' => 'completed']);
        }
    }

    /** Manual override: end an assignment early, regardless of block_end_date. */
    public function endEarly(ResourceAssignment $assignment): ResourceAssignment
    {
        if (! in_array($assignment->status, ['scheduled', 'active'], true)) {
            throw new \DomainException("Cannot end a ResourceAssignment with status '{$assignment->status}'.");
        }

        $assignment->update(['status' => 'completed', 'block_end_date' => BusinessTime::today()]);

        return $assignment->fresh();
    }

    /**
     * Manual override: extend a block past its originally planned end
     * date. Reactivates an assignment the scheduled job already closed,
     * if the new end date is still in the future - "extended past its
     * planned date" is a real override path, not just a data correction.
     */
    public function extend(ResourceAssignment $assignment, string $newEndDate): ResourceAssignment
    {
        if ($assignment->status === 'cancelled') {
            throw new \DomainException('Cannot extend a cancelled ResourceAssignment.');
        }

        $today = BusinessTime::today()->toDateString();
        $reactivate = $assignment->status === 'completed' && $newEndDate > $today;

        $assignment->update([
            'block_end_date' => $newEndDate,
            'status' => $reactivate ? 'active' : $assignment->status,
        ]);

        return $assignment->fresh();
    }

    public function cancel(ResourceAssignment $assignment): ResourceAssignment
    {
        if ($assignment->status === 'completed') {
            throw new \DomainException('Cannot cancel a completed ResourceAssignment.');
        }

        $assignment->update(['status' => 'cancelled']);

        return $assignment->fresh();
    }
}
