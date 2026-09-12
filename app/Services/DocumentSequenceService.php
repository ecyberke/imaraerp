<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Tenant;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * Architecture §3.10: a row-locked counter, not a Postgres SEQUENCE - gap
 * control requires the number to be rolled back if the enclosing
 * transaction fails, which a SEQUENCE structurally can't do. next() must
 * always be called inside the same transaction as the document insert it
 * numbers.
 */
class DocumentSequenceService
{
    /**
     * Exhaustive per architecture §3.10 - not "etc.": every KRA-relevant
     * fiscal document type gets its own prefix.
     */
    public const PREFIXES = [
        'invoice' => 'INV',
        'credit_note' => 'CN',
        'debit_note' => 'DN',
        'purchase_order' => 'PO',
        'purchase_requisition' => 'PR',
        'goods_receipt_note' => 'GRN',
        'quotation' => 'QT',
        'journal_entry' => 'JE',
    ];

    /**
     * Returns the next formatted document number for this tenant/entity
     * type, e.g. "INV-2026-00001". Must be called inside a transaction -
     * the row lock this takes only protects the counter for the lifetime
     * of the enclosing transaction.
     */
    public function next(Tenant $tenant, string $entityType): string
    {
        if (! array_key_exists($entityType, self::PREFIXES)) {
            throw new \InvalidArgumentException("Unknown DocumentSequence entity_type [{$entityType}].");
        }

        // fiscal_year resets on the Kenyan tax year (1 Jan), independent
        // of the tenant's own accounting AccountingPeriod calendar.
        $fiscalYear = BusinessTime::taxYearOf(BusinessTime::today());
        $prefix = self::PREFIXES[$entityType];

        $sequence = DocumentSequence::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', $entityType)
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            // firstOrCreate isn't safe here under concurrency (two
            // transactions could both miss and both try to insert) - the
            // unique(tenant_id, entity_type, fiscal_year) constraint is the
            // real backstop; a concurrent insert loses the race and retries
            // via the caller's transaction, which is the correct behavior
            // for what is already a serialization point by design (§1.1
            // accepts DocumentSequence's row lock as an intentional
            // contention point at Phase 1 scale).
            $sequence = DocumentSequence::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'entity_type' => $entityType,
                'prefix' => $prefix,
                'fiscal_year' => $fiscalYear,
                'next_number' => 1,
            ]);

            $sequence = DocumentSequence::withoutGlobalScopes()
                ->where('id', $sequence->id)
                ->lockForUpdate()
                ->first();
        }

        $number = $sequence->next_number;
        $sequence->increment('next_number');

        return sprintf('%s-%d-%05d', $prefix, $fiscalYear, $number);
    }
}
