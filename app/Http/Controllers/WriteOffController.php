<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\WriteOff;
use App\Services\WriteOffService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WriteOffController extends Controller
{
    public function __construct(private WriteOffService $writeOffs) {}

    public function store(Request $request)
    {
        $this->authorize('create', WriteOff::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string'],
        ]);

        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($data['invoice_id']);

        $writeOff = $this->writeOffs->writeOffInvoice($invoice, (string) $data['amount'], $data['reason'], $request->user());

        return response()->json($writeOff, 201);
    }
}
