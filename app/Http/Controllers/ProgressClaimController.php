<?php

namespace App\Http\Controllers;

use App\Models\ProgressClaim;
use App\Models\Subcontract;
use App\Services\ProgressClaimService;
use App\Support\Money;
use Illuminate\Http\Request;

class ProgressClaimController extends Controller
{
    public function __construct(private ProgressClaimService $claims)
    {
    }

    public function store(Request $request, Subcontract $subcontract)
    {
        $this->authorize('create', ProgressClaim::class);

        $data = $request->validate([
            'period' => ['required', 'string', 'max:20'],
            'amount_claimed' => ['required', 'numeric', 'gt:0'],
        ]);

        $claim = ProgressClaim::create([
            'tenant_id' => $subcontract->tenant_id,
            'subcontract_id' => $subcontract->id,
            'period' => $data['period'],
            'amount_claimed_cents' => Money::fromMajor($data['amount_claimed']),
            'status' => 'submitted',
        ]);

        return response()->json($claim, 201);
    }

    public function certify(Request $request, ProgressClaim $progressClaim)
    {
        $this->authorize('update', $progressClaim);

        $data = $request->validate([
            'amount_certified' => ['required', 'numeric', 'gt:0'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'retention_percentage' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $claim = $this->claims->certify(
            $progressClaim,
            Money::fromMajor($data['amount_certified']),
            (string) $data['vat_rate'],
            (string) $data['retention_percentage'],
            $request->user(),
        );

        return response()->json($claim);
    }

    public function reverse(Request $request, ProgressClaim $progressClaim)
    {
        $this->authorize('update', $progressClaim);

        $claim = $this->claims->reverse($progressClaim, $request->user());

        return response()->json($claim);
    }

    public function show(ProgressClaim $progressClaim)
    {
        $this->authorize('view', $progressClaim);

        return $progressClaim;
    }
}
