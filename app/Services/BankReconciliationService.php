<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * execution_plan.md: "BankAccount reconciliation UI beyond the Phase 1
 * minimal entity" - resolved as a lightweight MANUAL reconciliation
 * against the Payment rows that already exist, not the full Banking
 * module (statement import, BankTransaction, BankReconciliation as real
 * entities) §14 explicitly backlogs. See the payments migration's own
 * docblock for the full reasoning.
 */
class BankReconciliationService
{
    /** @return array{gl_balance: Money, unreconciled_total: Money, unreconciled_payments: \Illuminate\Support\Collection} */
    public function summary(BankAccount $bankAccount): array
    {
        $bankAccount->loadMissing('glAccount');

        $debit = (int) DB::table('journal_lines')->where('account_id', $bankAccount->gl_account_id)->sum('debit_cents');
        $credit = (int) DB::table('journal_lines')->where('account_id', $bankAccount->gl_account_id)->sum('credit_cents');
        $glBalance = Money::fromCents($debit - $credit);

        $unreconciled = Payment::where('bank_account_id', $bankAccount->id)->where('is_reconciled', false)->get();
        $unreconciledTotal = Money::sum(...$unreconciled->map(
            fn (Payment $p) => $p->direction === 'receipt' ? $p->amount : $p->amount->negate()
        )->all());

        return [
            'gl_balance' => $glBalance,
            'unreconciled_total' => $unreconciledTotal,
            'unreconciled_payments' => $unreconciled,
        ];
    }

    public function markReconciled(Payment $payment): Payment
    {
        if ($payment->is_reconciled) {
            throw new \DomainException('This Payment is already reconciled.');
        }

        $payment->update(['is_reconciled' => true, 'reconciled_at' => now()]);

        return $payment->fresh();
    }

    public function unmarkReconciled(Payment $payment): Payment
    {
        $payment->update(['is_reconciled' => false, 'reconciled_at' => null]);

        return $payment->fresh();
    }
}
