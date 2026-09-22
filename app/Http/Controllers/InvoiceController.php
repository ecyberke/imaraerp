<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return Invoice::where('tenant_id', $request->user()->tenant_id)->with('lines')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Invoice::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'sales_order_id' => ['required', 'integer', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            'payment_terms' => ['required', 'string', Rule::in(Invoice::PAYMENT_TERMS)],
            'tax_code' => ['nullable', 'string'],
            'retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.source_type' => ['nullable', 'string'],
            'lines.*.source_id' => ['nullable', 'integer'],
        ]);

        $salesOrder = SalesOrder::where('tenant_id', $tenantId)->findOrFail($data['sales_order_id']);

        $invoice = $this->invoices->create(
            $request->user()->tenant,
            $salesOrder,
            $data['lines'],
            $data['payment_terms'],
            $data['tax_code'] ?? 'VAT_STANDARD',
            isset($data['retention_percentage']) ? (string) $data['retention_percentage'] : null,
        );

        return response()->json($invoice, 201);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return $invoice->load('lines', 'retentionAccount');
    }

    public function raise(Request $request, Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'advance_payment_allocation_ids' => ['nullable', 'array'],
            'advance_payment_allocation_ids.*' => ['integer', Rule::exists('payment_allocations', 'id')->where('tenant_id', $tenantId)],
        ]);

        $invoice = $this->invoices->raise($invoice, $data['advance_payment_allocation_ids'] ?? [], $request->user());

        return response()->json($invoice);
    }
}
