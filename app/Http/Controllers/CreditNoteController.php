<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CreditNoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditNoteController extends Controller
{
    public function __construct(private CreditNoteService $creditNotes) {}

    public function store(Request $request)
    {
        $this->authorize('create', CreditNote::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'proportion' => ['required', 'numeric', 'gt:0', 'max:1'],
            'reason' => ['required', 'string', Rule::in(CreditNote::REASONS)],
        ]);

        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($data['invoice_id']);

        $creditNote = $this->creditNotes->issue($invoice, (string) $data['proportion'], $data['reason']);

        return response()->json($creditNote, 201);
    }
}
