<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\MpesaStkRequest;
use App\Models\Party;
use App\Services\MpesaService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MpesaStkRequestController extends Controller
{
    public function __construct(private MpesaService $mpesa) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', MpesaStkRequest::class);

        return MpesaStkRequest::where('tenant_id', $request->user()->tenant_id)
            ->with('party', 'invoice')->orderByDesc('created_at')->limit(50)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', MpesaStkRequest::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'phone_number' => ['required', 'string', 'max:20'],
        ]);

        $party = Party::where('tenant_id', $tenantId)->findOrFail($data['party_id']);
        $invoice = isset($data['invoice_id']) ? Invoice::where('tenant_id', $tenantId)->findOrFail($data['invoice_id']) : null;

        $stkRequest = $this->mpesa->initiateStkPush(
            $request->user()->tenant,
            $party,
            Money::fromMajor($data['amount']),
            $data['phone_number'],
            $invoice,
            $request->user(),
        );

        return response()->json($stkRequest, 201);
    }

    /** Polled by the frontend until status leaves 'pending' - the callback updates this same row asynchronously. */
    public function show(MpesaStkRequest $mpesaStkRequest)
    {
        $this->authorize('view', $mpesaStkRequest);

        return response()->json($mpesaStkRequest);
    }
}
