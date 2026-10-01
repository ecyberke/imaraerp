<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
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
}
