<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\SupplierPayment;
use App\Services\SupplierPaymentService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierPaymentController extends Controller
{
    public function __construct(private SupplierPaymentService $payments)
    {
    }

    public function store(Request $request)
    {
        $this->authorize('create', SupplierPayment::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'reference_type' => ['required', 'in:PurchaseOrder,Subcontract,ProgressClaim'],
            'reference_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'wht_withheld' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:cash,bank_transfer,cheque,mpesa'],
        ]);

        $party = Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']);

        $payment = $this->payments->pay(
            $party, $data['reference_type'], $data['reference_id'],
            Money::fromMajor($data['amount']),
            isset($data['wht_withheld']) ? Money::fromMajor($data['wht_withheld']) : null,
            $data['method'] ?? null,
        );

        return response()->json($payment, 201);
    }

    /**
     * §3.4/§7: AP was booked at the GRN's exchange rate; settled here at
     * a different rate, with the difference posting to FX Gain/Loss.
     */
    public function storeFxSettlement(Request $request)
    {
        $this->authorize('create', SupplierPayment::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'reference_type' => ['required', 'in:PurchaseOrder,Subcontract,ProgressClaim'],
            'reference_id' => ['required', 'integer'],
            'ap_portion_settled' => ['required', 'numeric', 'gt:0'],
            'cash_disbursed' => ['required', 'numeric', 'gt:0'],
            'settlement_exchange_rate' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', 'in:cash,bank_transfer,cheque,mpesa'],
        ]);

        $party = Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']);

        $payment = $this->payments->payWithFxSettlement(
            $party, $data['reference_type'], $data['reference_id'],
            Money::fromMajor($data['ap_portion_settled']),
            Money::fromMajor($data['cash_disbursed']),
            (string) $data['settlement_exchange_rate'],
            $data['method'] ?? null,
        );

        return response()->json($payment, 201);
    }
}
