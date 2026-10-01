<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Payment;
use App\Services\BankReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
    public function __construct(private BankReconciliationService $reconciliation) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', BankAccount::class);

        return BankAccount::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', BankAccount::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:100'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('tenant_id', $tenantId)],
            'gl_account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)],
        ]);

        $bankAccount = BankAccount::create([
            'tenant_id' => $tenantId,
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'currency_id' => $data['currency_id'],
            'gl_account_id' => $data['gl_account_id'],
            'status' => 'active',
        ]);

        return response()->json($bankAccount, 201);
    }

    /** Lightweight manual reconciliation - see BankReconciliationService's own docblock for scope. */
    public function reconciliationSummary(BankAccount $bankAccount)
    {
        $this->authorize('view', $bankAccount);

        return response()->json($this->reconciliation->summary($bankAccount));
    }

    public function markPaymentReconciled(Payment $payment)
    {
        abort_if(! $payment->bank_account_id, 422, 'This Payment has no BankAccount to reconcile against.');
        $this->authorize('view', $payment->bankAccount);

        return response()->json($this->reconciliation->markReconciled($payment));
    }

    public function unmarkPaymentReconciled(Payment $payment)
    {
        abort_if(! $payment->bank_account_id, 422, 'This Payment has no BankAccount to reconcile against.');
        $this->authorize('view', $payment->bankAccount);

        return response()->json($this->reconciliation->unmarkReconciled($payment));
    }
}
