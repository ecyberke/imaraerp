<?php

namespace App\Http\Controllers;

use App\Models\BillOfMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillOfMaterialController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', BillOfMaterial::class);

        return BillOfMaterial::where('tenant_id', $request->user()->tenant_id)->with('lines')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', BillOfMaterial::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'finished_good_item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            // Rate fields (percentages) reject negative outright; 0 is
            // valid (no wastage allowance / no tolerance).
            'wastage_allowance_pct' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'tolerance_pct' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'source' => ['required', 'in:uploaded,generated,manual'],
            'project_id' => ['nullable', 'integer'], // no FK yet - Project doesn't exist until projects-milestones-ui
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.raw_material_item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            // A line's quantity is a real transactional quantity - rejects
            // zero/negative outright (§1.1's cross-cutting rule; no
            // variation_type = omission carve-out applies to a BOM line).
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $bom = DB::transaction(function () use ($data, $tenantId) {
            $bom = BillOfMaterial::create([
                'tenant_id' => $tenantId,
                'finished_good_item_id' => $data['finished_good_item_id'],
                'wastage_allowance_pct' => $data['wastage_allowance_pct'] ?? 0,
                'tolerance_pct' => $data['tolerance_pct'] ?? null,
                'source' => $data['source'],
                'project_id' => $data['project_id'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $bom->lines()->create([
                    'tenant_id' => $tenantId,
                    'raw_material_item_id' => $line['raw_material_item_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            return $bom;
        });

        return response()->json($bom->load('lines'), 201);
    }

    public function show(BillOfMaterial $billOfMaterial)
    {
        $this->authorize('view', $billOfMaterial);

        return $billOfMaterial->load('lines');
    }

    public function update(Request $request, BillOfMaterial $billOfMaterial)
    {
        $this->authorize('update', $billOfMaterial);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'wastage_allowance_pct' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'tolerance_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'source' => ['sometimes', 'in:uploaded,generated,manual'],
        ]);

        $billOfMaterial->update($data);

        return $billOfMaterial->load('lines');
    }

    public function destroy(BillOfMaterial $billOfMaterial)
    {
        $this->authorize('delete', $billOfMaterial);

        $billOfMaterial->delete();

        return response()->noContent();
    }
}
