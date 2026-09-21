<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        return Lead::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Lead::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'source' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
        ]);

        $lead = Lead::create([
            'tenant_id' => $tenantId,
            'party_id' => $data['party_id'] ?? null,
            'source' => $data['source'] ?? null,
            'status' => $data['status'] ?? 'open',
        ]);

        return response()->json($lead, 201);
    }

    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        return $lead;
    }
}
