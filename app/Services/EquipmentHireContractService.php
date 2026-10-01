<?php

namespace App\Services;

use App\Models\EquipmentHireContract;
use App\Models\Party;
use App\Models\Project;
use App\Support\Money;

class EquipmentHireContractService
{
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
     * by hand." hire_invoice_mismatch stands in for the not-yet-built
     * Notification (see the migration docblock).
     */
    public function recordInvoicedDays(EquipmentHireContract $contract, int $invoicedDays): EquipmentHireContract
    {
        $contract->update([
            'invoiced_days' => $invoicedDays,
            'hire_invoice_mismatch' => $contract->expected_days !== null && $invoicedDays !== $contract->expected_days,
        ]);

        return $contract->fresh();
    }
}
