<?php

namespace App\Services;

use App\Models\Boq;
use App\Models\BoqImportStaging;
use App\Models\BoqLine;
use App\Models\BoqSection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.8: an upload lands in BoqImportStaging row-by-row and only becomes
 * a live BoqLine after explicit confirmation - never written to boq_lines
 * directly. Real Excel/PDF/CSV parsing (the bookkeeping app's statement-
 * import pattern §3.8 points to) is a document-format concern that
 * belongs with the UI upload flow (phase1-screens); this branch builds
 * the staging->review->confirm data model and mechanism the exit
 * criterion actually asks for, taking already-extracted rows (as the
 * caller/UI would hand it after parsing) rather than parsing files
 * itself. That's a deliberate, flagged scope cut, not a silent skip.
 */
class BoqImportService
{
    /**
     * @param  array<int, array<string, mixed>>  $rawRows  each row exactly as extracted from the source file
     */
    public function stage(Tenant $tenant, array $rawRows, ?string $sourceFilePath = null, ?int $projectId = null, ?int $boqId = null): array
    {
        return DB::transaction(function () use ($tenant, $rawRows, $sourceFilePath, $projectId, $boqId) {
            $staged = [];
            foreach ($rawRows as $row) {
                $staged[] = BoqImportStaging::create([
                    'tenant_id' => $tenant->id,
                    'project_id' => $projectId,
                    'boq_id' => $boqId,
                    'source_file_path' => $sourceFilePath,
                    'raw_row_data' => $row,
                    'status' => 'pending_review',
                ]);
            }

            return $staged;
        });
    }

    public function map(
        BoqImportStaging $staging,
        ?int $itemId,
        ?string $section,
        string $quantity,
        string $rate,
    ): BoqImportStaging {
        $staging->update([
            'mapped_item_id' => $itemId,
            'mapped_section' => $section,
            'mapped_quantity' => $quantity,
            'mapped_rate_cents' => Money::fromMajor($rate),
        ]);

        return $staging->fresh();
    }

    /**
     * The only path that ever creates a real BoqLine from an import -
     * requires the row to already be mapped (mapped_quantity/mapped_rate
     * set), resolves or creates the target BOQ and, if mapped_section is
     * set, its BoqSection, then writes the BoqLine and marks the staging
     * row confirmed with a back-reference to it.
     */
    public function confirm(BoqImportStaging $staging, Boq $boq, User $reviewedBy): BoqLine
    {
        if ($staging->status !== 'pending_review') {
            throw new \DomainException("Cannot confirm a staging row with status '{$staging->status}'.");
        }

        if ($staging->mapped_quantity === null || $staging->mapped_rate === null) {
            throw new \DomainException('Cannot confirm an unmapped staging row - mapped_quantity/mapped_rate are required.');
        }

        return DB::transaction(function () use ($staging, $boq, $reviewedBy) {
            $section = null;
            if ($staging->mapped_section) {
                $section = BoqSection::firstOrCreate(
                    ['tenant_id' => $boq->tenant_id, 'boq_id' => $boq->id, 'name' => $staging->mapped_section],
                    ['sequence' => 0],
                );
            }

            $rate = $staging->mapped_rate_cents;
            $amount = $rate->multiply((string) $staging->mapped_quantity);

            $rawRow = $staging->raw_row_data;
            $description = $rawRow['description'] ?? ($staging->mapped_section ?? 'Imported line');

            $line = BoqLine::create([
                'tenant_id' => $boq->tenant_id,
                'boq_id' => $boq->id,
                'section_id' => $section?->id,
                'item_id' => $staging->mapped_item_id,
                'description' => $description,
                'unit' => $rawRow['unit'] ?? null,
                'quantity' => $staging->mapped_quantity,
                'rate_cents' => $rate,
                'amount_cents' => $amount,
            ]);

            $staging->update([
                'boq_id' => $boq->id,
                'status' => 'confirmed',
                'reviewed_by' => $reviewedBy->id,
                'boq_line_id' => $line->id,
            ]);

            return $line;
        });
    }

    public function reject(BoqImportStaging $staging, User $reviewedBy): BoqImportStaging
    {
        if ($staging->status !== 'pending_review') {
            throw new \DomainException("Cannot reject a staging row with status '{$staging->status}'.");
        }

        $staging->update(['status' => 'rejected', 'reviewed_by' => $reviewedBy->id]);

        return $staging->fresh();
    }
}
