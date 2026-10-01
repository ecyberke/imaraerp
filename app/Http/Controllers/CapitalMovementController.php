<?php

namespace App\Http\Controllers;

use App\Models\CapitalMovement;
use App\Models\Party;
use App\Services\CapitalMovementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CapitalMovementController extends Controller
{
    public function __construct(private CapitalMovementService $movements) {}

    public function store(Request $request)
    {
        $this->authorize('create', CapitalMovement::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'direction' => ['required', 'string', Rule::in(CapitalMovement::DIRECTIONS)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
        ]);

        $party = isset($data['party_id']) ? Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']) : null;

        $movement = $this->movements->record($request->user()->tenant, $data['direction'], (string) $data['amount'], $party);

        return response()->json($movement, 201);
    }
}
