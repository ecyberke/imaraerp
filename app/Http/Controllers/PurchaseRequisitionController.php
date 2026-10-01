<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequisition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseRequisitionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseRequisition::class);

        return PurchaseRequisition::where('tenant_id', $request->user()->tenant_id)->with('lines')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', PurchaseRequisition::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'demand_trigger_id' => ['nullable', 'integer', Rule::exists('demand_triggers', 'id')->where('tenant_id', $tenantId)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity_needed' => ['required', 'numeric', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        $requisition = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $tenantId) {
            $requisition = PurchaseRequisition::create([
                'tenant_id' => $tenantId,
                'demand_trigger_id' => $data['demand_trigger_id'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($data['lines'] as $line) {
                $requisition->lines()->create([
                    'tenant_id' => $tenantId,
                    'item_id' => $line['item_id'],
                    'quantity_needed' => $line['quantity_needed'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $requisition;
        });

        return response()->json($requisition->load('lines'), 201);
    }

    public function show(PurchaseRequisition $purchaseRequisition)
    {
        $this->authorize('view', $purchaseRequisition);

        return $purchaseRequisition->load('lines');
    }

    public function approve(PurchaseRequisition $purchaseRequisition)
    {
        $this->authorize('update', $purchaseRequisition);

        $purchaseRequisition->update(['status' => 'approved']);

        return $purchaseRequisition;
    }
}
