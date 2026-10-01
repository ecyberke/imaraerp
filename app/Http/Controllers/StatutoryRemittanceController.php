<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\StatutoryRemittance;
use App\Services\StatutoryRemittanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatutoryRemittanceController extends Controller
{
    public function __construct(private StatutoryRemittanceService $remittances) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PayrollRun::class);

        return StatutoryRemittance::where('tenant_id', $request->user()->tenant_id)->orderByDesc('period')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', PayrollRun::class);

        $data = $request->validate([
            'period' => ['required', 'date'],
            'authority' => ['required', 'string', Rule::in(StatutoryRemittance::AUTHORITIES)],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json($this->remittances->record($request->user()->tenant, $data['period'], $data['authority'], (string) $data['amount']), 201);
    }

    public function pay(Request $request, StatutoryRemittance $statutoryRemittance)
    {
        $this->authorize('update', $statutoryRemittance);

        $data = $request->validate(['reference_number' => ['required', 'string', 'max:255']]);

        return response()->json($this->remittances->pay($statutoryRemittance, $data['reference_number']));
    }
}
