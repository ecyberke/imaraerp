<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Support\Money;
use Illuminate\Http\Request;

class PartyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Party::class);

        return Party::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Party::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:customer,supplier,contractor,both'],
            // Rate/amount fields reject negative outright (§1.1). A major-
            // unit KES amount from the request - explicitly converted via
            // Money::fromMajor() below, never handed to the _cents column
            // as a raw int (MoneyCast's set() treats a bare int as already
            // being cents, which a request payload's credit_limit is not).
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'in:credit,cash'],
            'tax_residency_status' => ['nullable', 'in:resident_certified,resident_uncertified,non_resident'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $party = Party::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'type' => $data['type'],
            'credit_limit_cents' => Money::fromMajor($data['credit_limit'] ?? 0),
            'payment_terms' => $data['payment_terms'] ?? null,
            'tax_residency_status' => $data['tax_residency_status'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($party, 201);
    }

    public function show(Party $party)
    {
        $this->authorize('view', $party);

        return $party;
    }

    public function update(Request $request, Party $party)
    {
        $this->authorize('update', $party);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:customer,supplier,contractor,both'],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payment_terms' => ['sometimes', 'nullable', 'in:credit,cash'],
            'tax_residency_status' => ['sometimes', 'nullable', 'in:resident_certified,resident_uncertified,non_resident'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('credit_limit', $data)) {
            $data['credit_limit_cents'] = Money::fromMajor($data['credit_limit'] ?? 0);
            unset($data['credit_limit']);
        }

        $party->update($data);

        return $party;
    }

    public function destroy(Party $party)
    {
        $this->authorize('delete', $party);

        $party->delete();

        return response()->noContent();
    }
}
