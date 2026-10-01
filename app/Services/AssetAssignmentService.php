<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.12: AssetAssignment only applies to plant_equipment - a fixed_asset
 * (office furniture, etc.) is never project-assignable.
 */
class AssetAssignmentService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function assign(Asset $asset, Project $project, string $assignedDate, string $internalDailyRateMajor, ?string $meterReadingStart = null): AssetAssignment
    {
        if ($asset->asset_type !== 'plant_equipment') {
            throw new \DomainException("Asset #{$asset->id} is a '{$asset->asset_type}' - only plant_equipment can be assigned to a Project.");
        }

        return AssetAssignment::create([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'assigned_date' => $assignedDate,
            'internal_daily_rate_cents' => Money::fromMajor($internalDailyRateMajor),
            'meter_reading_start' => $meterReadingStart,
            'status' => 'active',
        ]);
    }

    public function release(AssetAssignment $assignment, string $releasedDate, ?string $meterReadingEnd = null): AssetAssignment
    {
        if ($assignment->status !== 'active') {
            throw new \DomainException('This AssetAssignment is not active.');
        }

        $assignment->update([
            'released_date' => $releasedDate,
            'meter_reading_end' => $meterReadingEnd,
            'status' => 'released',
        ]);

        return $assignment->fresh();
    }

    /**
     * §7: "Dr Project Equipment Cost, tagged with the project's
     * analytic_account_id; Cr Internal Equipment Recovery" - the
     * analytic tag comes from the assignment's own Project, via
     * Project.analytic_account_id (see the projects migration docblock,
     * fixed-assets-plant, for why that link didn't exist before now).
     */
    public function postPeriodicCharge(AssetAssignment $assignment, int $days): JournalEntry
    {
        $assignment->loadMissing('project.analyticAccount');
        $analyticAccount = $assignment->project->analyticAccount;

        if (! $analyticAccount) {
            throw new \DomainException("Project #{$assignment->project_id} has no AnalyticAccount - cannot post an analytic-tagged internal equipment charge.");
        }

        return DB::transaction(function () use ($assignment, $days, $analyticAccount) {
            $entryInput = $this->ledger->postInternalEquipmentCharge(
                assignmentReference: (string) $assignment->id,
                dailyRate: $assignment->internal_daily_rate,
                days: $days,
                analyticAccountCode: $analyticAccount->cost_code,
            );

            return $this->ledger->commit($assignment->tenant, $entryInput, BusinessTime::today(), null, $assignment->id);
        });
    }
}
