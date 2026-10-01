<?php

namespace App\Services;

use App\Models\EquipmentHireContract;
use App\Models\Party;
use App\Models\Project;
use App\Support\Money;

class EquipmentHireContractService
{
    public function __construct(private NotificationService $notifications) {}

    public function create(Party $party, Project $project, array $data): EquipmentHireContract
    {
        return EquipmentHireContract::create([
            'tenant_id' => $party->tenant_id,
            'party_id' => $party->id,
            'project_id' => $project->id,
            'description' => $data['description'],
            'hire_rate_cents' => Money::fromMajor($data['hire_rate']),
            'hire_start_date' => $data['hire_start_date'],
            'hire_end_date' => $data['hire_end_date'] ?? null,
        ]);
    }

    /**
     * §3.12: "when the hire company's invoice/PO line claims a different
     * number of days than expected_days, that's the discrepancy check...
     * worth surfacing rather than requiring someone to remember to check
     * by hand." hire_invoice_mismatch is the stored fact; the Notification
     * below (type=hire_invoice_mismatch) is the real alert.
     */
    public function recordInvoicedDays(EquipmentHireContract $contract, int $invoicedDays): EquipmentHireContract
    {
        $mismatch = $contract->expected_days !== null && $invoicedDays !== $contract->expected_days;

        $contract->update(['invoiced_days' => $invoicedDays, 'hire_invoice_mismatch' => $mismatch]);

        if ($mismatch) {
            $this->notifications->notifyRole(
                $contract->tenant, 'asset_manager', 'hire_invoice_mismatch',
                "EquipmentHireContract #{$contract->id} ({$contract->description}): invoiced {$invoicedDays} days, expected {$contract->expected_days}.",
                EquipmentHireContract::class, $contract->id,
            );
        }

        return $contract->fresh();
    }
}
