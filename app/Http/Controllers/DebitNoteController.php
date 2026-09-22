<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Services\DebitNoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DebitNoteController extends Controller
{
    public function __construct(private DebitNoteService $debitNotes) {}

    public function store(Request $request)
    {
        $this->authorize('create', DebitNote::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'proportion' => ['required', 'numeric', 'gt:0', 'max:1'],
            'reason' => ['required', 'string', Rule::in(CreditNote::REASONS)],
        ]);

        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($data['invoice_id']);

        $debitNote = $this->debitNotes->issue($invoice, (string) $data['proportion'], $data['reason']);

        return response()->json($debitNote, 201);
    }
}
