<?php

namespace App\Http\Controllers;

use App\Models\Subcontract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubcontractController extends Controller
{
    public function store(Request $request)
    {
        $this->authorize('create', Subcontract::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'project_id' => ['nullable', 'integer'], // no FK yet - projects-milestones-ui
            'boq_id' => ['nullable', 'integer'], // no FK yet - crm-sales-boq
            'required_document_types' => ['nullable', 'array'],
            'required_document_types.*' => ['string'],
        ]);

        $subcontract = Subcontract::create([...$data, 'tenant_id' => $tenantId, 'status' => 'active']);

        return response()->json($subcontract, 201);
    }

    public function show(Subcontract $subcontract)
    {
        $this->authorize('view', $subcontract);

        return $subcontract->load('progressClaims');
    }
}
