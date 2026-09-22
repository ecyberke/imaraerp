<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function store(Request $request)
    {
        $this->authorize('create', Payment::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'invoice_allocations' => ['nullable', 'array'],
            'invoice_allocations.*.invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'invoice_allocations.*.amount' => ['required', 'numeric', 'gt:0'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'wht_withheld_by_client' => ['nullable', 'numeric', 'min:0'],
            'wht_tax_code' => ['nullable', 'string'],
            'method' => ['required', 'string', Rule::in(Payment::METHODS)],
            'mpesa_reference' => ['nullable', 'string'],
        ]);

        $party = Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']);

        $payment = $this->payments->receive(
            $request->user()->tenant,
            $party,
            $data['invoice_allocations'] ?? [],
            isset($data['advance_amount']) ? (string) $data['advance_amount'] : '0',
            isset($data['wht_withheld_by_client']) ? (string) $data['wht_withheld_by_client'] : null,
            $data['wht_tax_code'] ?? null,
            $data['method'],
            $data['mpesa_reference'] ?? null,
            $request->user(),
        );

        return response()->json($payment, 201);
    }

    public function refundAdvance(Request $request, PaymentAllocation $paymentAllocation)
    {
        $this->authorize('create', Payment::class);

        $allocation = $this->payments->refundAdvance($paymentAllocation, $request->user());

        return response()->json($allocation);
    }
}
