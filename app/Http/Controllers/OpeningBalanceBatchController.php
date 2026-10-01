<?php

namespace App\Http\Controllers;

use App\Models\OpeningBalanceBatch;
use App\Services\DocumentSequenceService;
use App\Services\OpeningBalanceBatchService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpeningBalanceBatchController extends Controller
{
    public function __construct(private OpeningBalanceBatchService $batches) {}

    public function store(Request $request)
    {
        $this->authorize('create', OpeningBalanceBatch::class);

        $data = $request->validate(['as_of_date' => ['required', 'date']]);

        $batch = $this->batches->create($request->user()->tenant, \Carbon\Carbon::parse($data['as_of_date']));

        return response()->json($batch, 201);
    }

    public function addInvoice(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $this->authorize('update', $openingBalanceBatch);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId)],
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'invoice_date' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
            'payment_terms' => ['required', 'string', Rule::in(['credit', 'cash'])],
            'gross_amount' => ['required', 'numeric', 'min:0'],
            'vat_amount' => ['required', 'numeric', 'min:0'],
            'retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'retention_amount' => ['nullable', 'numeric', 'min:0'],
            'net_payable' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'string', Rule::in(['raised', 'partially_paid', 'paid'])],
        ]);

        $invoice = $this->batches->addOpeningInvoice($openingBalanceBatch, [
            'sales_order_id' => $data['sales_order_id'] ?? null,
            'party_id' => $data['party_id'],
            'invoice_date' => $data['invoice_date'],
            'posting_date' => $data['posting_date'],
            'payment_terms' => $data['payment_terms'],
            'credit_approval_status' => $data['payment_terms'] === 'cash' ? 'not_required' : 'approved',
            'gross_amount_cents' => Money::fromMajor($data['gross_amount']),
            'vat_amount_cents' => Money::fromMajor($data['vat_amount']),
            'retention_percentage' => $data['retention_percentage'] ?? 0,
            'retention_amount_cents' => Money::fromMajor($data['retention_amount'] ?? 0),
            'net_payable_cents' => Money::fromMajor($data['net_payable']),
            'status' => $data['status'],
        ]);

        return response()->json($invoice, 201);
    }

    public function addPayment(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $this->authorize('update', $openingBalanceBatch);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'direction' => ['required', 'string', Rule::in(['receipt', 'disbursement'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string'],
            'received_at' => ['required', 'date'],
            'posting_date' => ['required', 'date'],
        ]);

        $payment = $this->batches->addOpeningPayment($openingBalanceBatch, [
            'party_id' => $data['party_id'],
            'direction' => $data['direction'],
            'amount_cents' => Money::fromMajor($data['amount']),
            'method' => $data['method'],
            'received_at' => $data['received_at'],
            'posting_date' => $data['posting_date'],
        ]);

        return response()->json($payment, 201);
    }

    public function addPaymentAllocation(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $this->authorize('update', $openingBalanceBatch);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'payment_id' => ['required', 'integer', Rule::exists('payments', 'id')->where('tenant_id', $tenantId)],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'amount_allocated' => ['required', 'numeric', 'gt:0'],
        ]);

        $allocation = $this->batches->addOpeningPaymentAllocation($openingBalanceBatch, [
            'payment_id' => $data['payment_id'],
            'invoice_id' => $data['invoice_id'] ?? null,
            'amount_allocated_cents' => Money::fromMajor($data['amount_allocated']),
            'status' => 'allocated',
        ]);

        return response()->json($allocation, 201);
    }

    public function addStock(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $this->authorize('update', $openingBalanceBatch);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $row = $this->batches->addOpeningStock($openingBalanceBatch, [
            'item_id' => $data['item_id'],
            'warehouse_id' => $data['warehouse_id'],
            'quantity' => $data['quantity'],
            'unit_cost_cents' => Money::fromMajor($data['unit_cost']),
        ]);

        return response()->json($row, 201);
    }

    public function initializeDocumentSequence(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $this->authorize('update', $openingBalanceBatch);

        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(array_keys(DocumentSequenceService::PREFIXES))],
            'last_issued_number' => ['required', 'integer', 'min:0'],
            'fiscal_year' => ['nullable', 'integer'],
        ]);

        $sequence = $this->batches->initializeDocumentSequence(
            $openingBalanceBatch->tenant, $data['entity_type'], $data['last_issued_number'], $data['fiscal_year'] ?? null,
        );

        return response()->json($sequence, 201);
    }

    public function post(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $this->authorize('update', $openingBalanceBatch);

        $data = $request->validate([
            'debit_balances' => ['required', 'array', 'min:1'],
            'debit_balances.*' => ['numeric'],
            'credit_balances' => ['required', 'array', 'min:1'],
            'credit_balances.*' => ['numeric'],
        ]);

        $debits = array_map(fn ($v) => Money::fromMajor($v), $data['debit_balances']);
        $credits = array_map(fn ($v) => Money::fromMajor($v), $data['credit_balances']);

        $batch = $this->batches->post($openingBalanceBatch, $debits, $credits, $request->user());

        return response()->json($batch);
    }
}
