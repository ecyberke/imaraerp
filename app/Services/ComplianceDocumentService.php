<?php

namespace App\Services;

use App\Models\ComplianceDocument;
use App\Models\Party;
use App\Models\Subcontract;
use App\Support\BusinessTime;

/**
 * §3.10: "Blocking point: ProgressClaim.status transitioning to
 * certified ... reads Subcontract.required_document_types for that
 * specific subcontract ... expiry_date is the last valid day, inclusive."
 */
class ComplianceDocumentService
{
    public function create(Party $party, array $data): ComplianceDocument
    {
        $document = ComplianceDocument::create([
            ...$data,
            'tenant_id' => $party->tenant_id,
            'party_id' => $party->id,
            'status' => 'valid',
        ]);

        return $this->refreshStatus($document);
    }

    public function refreshStatus(ComplianceDocument $document): ComplianceDocument
    {
        $document->update(['status' => $this->computeStatus($document)]);

        return $document->fresh();
    }

    /** Inclusive expiry boundary: the expiry_date itself is still valid. "Expiring soon" is within 30 days of that boundary. */
    private function computeStatus(ComplianceDocument $document): string
    {
        if (! $document->expiry_date) {
            return 'valid';
        }

        $today = BusinessTime::today();
        if ($today->greaterThan($document->expiry_date)) {
            return 'expired';
        }

        if ($today->diffInDays($document->expiry_date) <= 30) {
            return 'expiring_soon';
        }

        return 'valid';
    }

    /**
     * §3.10: checked against Subcontract.required_document_types, not a
     * global list - a subcontract with no required types configured has
     * nothing to block against.
     *
     * @return string[] the missing/invalid document_types, empty if clear to certify
     */
    public function missingOrInvalidDocumentTypes(Subcontract $subcontract): array
    {
        $required = $subcontract->required_document_types ?? [];
        if (empty($required)) {
            return [];
        }

        $validTypes = ComplianceDocument::where('tenant_id', $subcontract->tenant_id)
            ->where('party_id', $subcontract->party_id)
            ->whereIn('document_type', $required)
            ->get()
            ->filter(fn (ComplianceDocument $doc) => $this->computeStatus($doc) !== 'expired')
            ->pluck('document_type')
            ->unique();

        return array_values(array_diff($required, $validTypes->all()));
    }
}
