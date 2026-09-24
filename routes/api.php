<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BillOfMaterialController;
use App\Http\Controllers\BoqController;
use App\Http\Controllers\BoqImportStagingController;
use App\Http\Controllers\CapitalMovementController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\ContractRetentionTermsController;
use App\Http\Controllers\CreditApprovalController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebitNoteController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DemandTriggerController;
use App\Http\Controllers\DummyRecordController;
use App\Http\Controllers\GoodsReceiptNoteController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LandedCostController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MeasurementSheetController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\OpeningBalanceBatchController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProgressClaimController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RetentionReleaseController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\StockAvailabilityController;
use App\Http\Controllers\StockReceivingController;
use App\Http\Controllers\StockReservationController;
use App\Http\Controllers\SubcontractController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\UnitOfMeasureController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WriteOffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Rate-limited auth endpoints (architecture §1.1's security baseline) -
// both unauthenticated by design (login has no session yet; accepting an
// invitation is how the account it would log into first gets created).
// This IP-keyed throttle guards against raw request flooding; it's
// deliberately looser than LoginController's own email-keyed account
// lockout (5 failed attempts / 15 min), which is the actual brute-force
// defense and works regardless of how many IPs an attacker spreads
// across - the two are complementary, not duplicates.
Route::post('/auth/login', [LoginController::class, 'login'])->middleware('throttle:30,1');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:30,1');

// Readiness probe (§1.1): DB + queue connectivity. Unauthenticated, like
// Laravel's own /up liveness route - an infra probe can't hold a bearer
// token. The 'database' queue driver shares the DB connection this
// checks, so a separate queue check would be redundant at Phase 1; that
// changes if/when the queue connection moves off 'database'.
Route::get('/ready', function () {
    try {
        DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Throwable) {
        $dbOk = false;
    }

    return response()->json(['ready' => $dbOk, 'db' => $dbOk], $dbOk ? 200 : 503);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/health', function (Request $request) {
        return response()->json([
            'ok' => true,
            'user_id' => $request->user()->id,
            'tenant_id' => $request->user()->tenant_id,
        ]);
    });

    Route::post('/mfa/setup', [MfaController::class, 'setup']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm']);

    // Revokes every other Sanctum token on success (§1.1).
    Route::patch('/account/password', [AccountController::class, 'changePassword']);

    // 'mfa' gates creation for Finance/Admin roles without MFA enrolled
    // (§1.1); 'idempotent' honors an optional Idempotency-Key header
    // (§1.1/§3.10) - both the pattern every real financial-entity
    // mutation route reuses from ledger-core onward.
    Route::post('/dummy-records', [DummyRecordController::class, 'store'])->middleware(['mfa', 'idempotent']);
    Route::get('/dummy-records/{dummyRecord}', [DummyRecordController::class, 'show']);

    // master-data: §1.1's MFA mandate is stated per-role ("mandatory for
    // Finance and Admin"), not scoped to a narrow set of "financial"
    // routes - applied here to every mutation the same way, not just
    // read endpoints, so an Admin without MFA is consistently blocked
    // everywhere, not just on the routes that happen to look financial.
    Route::apiResource('parties', PartyController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'mfa');
    Route::apiResource('categories', CategoryController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'mfa');
    Route::apiResource('units-of-measure', UnitOfMeasureController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'mfa');
    Route::apiResource('items', ItemController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'mfa');
    Route::apiResource('bill-of-materials', BillOfMaterialController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'mfa');

    // inventory-core (§3.3/§6): the GRN-receipt -> QC -> stock_ledger
    // round trip, availability, and the locked check-and-reserve
    // mechanism - see StockReceivingService/StockAvailabilityService for
    // why GoodsReceiptNote/SalesOrderReservation aren't real entities yet.
    Route::apiResource('warehouses', WarehouseController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor(['store'], 'mfa');
    Route::post('/stock/quarantine', [StockReceivingController::class, 'storeQuarantine'])->middleware('mfa');
    Route::get('/stock/quarantine/{stockQuarantine}', [StockReceivingController::class, 'showQuarantine']);
    Route::post('/stock/quality-checks', [StockReceivingController::class, 'storeQualityCheck'])->middleware('mfa');
    Route::get('/stock/availability', [StockAvailabilityController::class, 'show']);
    Route::post('/stock/reservations', [StockReservationController::class, 'store'])->middleware('mfa');
    Route::get('/stock/reservations/{stockReservation}', [StockReservationController::class, 'show']);

    // procurement (§3.4/§5.2): DemandTrigger -> PR -> PO -> GRN -> QC ->
    // ledger; Subcontract/ProgressClaim certify+reverse; supplier
    // payments (incl. FX settlement) and post-acceptance returns.
    Route::get('/demand-triggers', [DemandTriggerController::class, 'index']);
    Route::post('/demand-triggers/check', [DemandTriggerController::class, 'check'])->middleware('mfa');

    Route::get('/purchase-requisitions', [PurchaseRequisitionController::class, 'index']);
    Route::post('/purchase-requisitions', [PurchaseRequisitionController::class, 'store'])->middleware('mfa');
    Route::get('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'show']);
    Route::patch('/purchase-requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve'])->middleware('mfa');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('mfa');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);

    Route::post('/purchase-orders/{purchaseOrder}/goods-receipt-notes', [GoodsReceiptNoteController::class, 'store'])->middleware('mfa');
    Route::get('/goods-receipt-notes/{goodsReceiptNote}', [GoodsReceiptNoteController::class, 'show']);
    Route::post('/goods-receipt-notes/{goodsReceiptNote}/lines', [GoodsReceiptNoteController::class, 'receiveLine'])->middleware('mfa');
    Route::post('/goods-receipt-notes/{goodsReceiptNote}/landed-costs', [LandedCostController::class, 'store'])->middleware('mfa');
    Route::post('/grn-lines/{grnLine}/quality-checks', [GoodsReceiptNoteController::class, 'recordQualityCheck'])->middleware('mfa');

    Route::get('/subcontracts', [SubcontractController::class, 'index']);
    Route::post('/subcontracts', [SubcontractController::class, 'store'])->middleware('mfa');
    Route::get('/subcontracts/{subcontract}', [SubcontractController::class, 'show']);
    Route::post('/subcontracts/{subcontract}/progress-claims', [ProgressClaimController::class, 'store'])->middleware('mfa');
    Route::get('/progress-claims/{progressClaim}', [ProgressClaimController::class, 'show']);
    Route::post('/progress-claims/{progressClaim}/certify', [ProgressClaimController::class, 'certify'])->middleware('mfa');
    Route::post('/progress-claims/{progressClaim}/reverse', [ProgressClaimController::class, 'reverse'])->middleware('mfa');

    Route::post('/supplier-payments', [SupplierPaymentController::class, 'store'])->middleware('mfa');
    Route::post('/supplier-payments/fx-settlement', [SupplierPaymentController::class, 'storeFxSettlement'])->middleware('mfa');
    Route::post('/supplier-returns', [SupplierReturnController::class, 'store'])->middleware('mfa');

    // crm-sales-boq (§3.2/§5.1): Lead -> Quotation/SalesOrder ->
    // Feasibility -> (Direct Sale) stock reservation against
    // inventory-core; Delivery/SalesReturn; the BOQ family and its
    // upload-to-staging-to-confirm pipeline.
    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store'])->middleware('mfa');
    Route::get('/leads/{lead}', [LeadController::class, 'show']);

    Route::get('/sales-orders', [SalesOrderController::class, 'index']);
    Route::post('/sales-orders', [SalesOrderController::class, 'store'])->middleware('mfa');
    Route::get('/sales-orders/{salesOrder}', [SalesOrderController::class, 'show']);
    Route::post('/sales-orders/{salesOrder}/submit-for-feasibility', [SalesOrderController::class, 'submitForFeasibility'])->middleware('mfa');
    Route::post('/sales-orders/{salesOrder}/feasibility-assessments', [SalesOrderController::class, 'assessFeasibility'])->middleware('mfa');
    Route::post('/sales-orders/{salesOrder}/resubmit', [SalesOrderController::class, 'resubmit'])->middleware('mfa');
    Route::post('/sales-orders/{salesOrder}/reserve-stock', [SalesOrderController::class, 'reserveStock'])->middleware('mfa');

    Route::get('/sales-orders/{salesOrder}/deliveries', [DeliveryController::class, 'indexForSalesOrder']);
    Route::post('/sales-orders/{salesOrder}/deliveries', [DeliveryController::class, 'store'])->middleware('mfa');
    Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::post('/deliveries/{delivery}/lines', [DeliveryController::class, 'addLine'])->middleware('mfa');
    Route::post('/deliveries/{delivery}/mark-delivered', [DeliveryController::class, 'markDelivered'])->middleware('mfa');
    Route::post('/deliveries/{delivery}/sales-returns', [SalesReturnController::class, 'store'])->middleware('mfa');

    Route::get('/boqs', [BoqController::class, 'index']);
    Route::post('/boqs', [BoqController::class, 'store'])->middleware('mfa');
    Route::get('/boqs/{boq}', [BoqController::class, 'show']);

    Route::get('/boq-lines/{boqLine}/measurement-sheets', [MeasurementSheetController::class, 'indexForLine']);
    Route::post('/boq-lines/{boqLine}/measurement-sheets', [MeasurementSheetController::class, 'store'])->middleware('mfa');
    Route::post('/measurement-sheets/{measurementSheet}/certify', [MeasurementSheetController::class, 'certify'])->middleware('mfa');

    Route::get('/boq-import-stagings', [BoqImportStagingController::class, 'index']);
    Route::post('/boq-import-stagings', [BoqImportStagingController::class, 'store'])->middleware('mfa');
    Route::patch('/boq-import-stagings/{boqImportStaging}/map', [BoqImportStagingController::class, 'map'])->middleware('mfa');
    Route::post('/boq-import-stagings/{boqImportStaging}/confirm', [BoqImportStagingController::class, 'confirm'])->middleware('mfa');
    Route::post('/boq-import-stagings/{boqImportStaging}/reject', [BoqImportStagingController::class, 'reject'])->middleware('mfa');

    // finance-billing (§3.9): Invoice -> CreditApproval -> raise ->
    // Payment/PaymentAllocation, CreditNote/DebitNote corrections,
    // Write-off, RetentionAccount/ContractRetentionTerms/
    // RetentionRelease, CapitalMovement, and the OpeningBalanceBatch
    // migration mechanism.
    Route::post('/contract-retention-terms', [ContractRetentionTermsController::class, 'store'])->middleware('mfa');

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('mfa');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoice}/raise', [InvoiceController::class, 'raise'])->middleware('mfa');

    Route::post('/credit-approvals', [CreditApprovalController::class, 'store'])->middleware('mfa');
    Route::post('/credit-approvals/{creditApproval}/override', [CreditApprovalController::class, 'override'])->middleware('mfa');

    Route::post('/credit-notes', [CreditNoteController::class, 'store'])->middleware('mfa');
    Route::post('/debit-notes', [DebitNoteController::class, 'store'])->middleware('mfa');
    Route::post('/write-offs', [WriteOffController::class, 'store'])->middleware('mfa');

    Route::post('/retention-releases', [RetentionReleaseController::class, 'store'])->middleware('mfa');
    Route::post('/retention-releases/{retentionRelease}/mark-ready', [RetentionReleaseController::class, 'markReady'])->middleware('mfa');
    Route::post('/retention-releases/{retentionRelease}/release', [RetentionReleaseController::class, 'release'])->middleware('mfa');

    Route::post('/payments', [PaymentController::class, 'store'])->middleware('mfa');
    Route::post('/payment-allocations/{paymentAllocation}/refund', [PaymentController::class, 'refundAdvance'])->middleware('mfa');

    Route::post('/capital-movements', [CapitalMovementController::class, 'store'])->middleware('mfa');

    Route::post('/opening-balance-batches', [OpeningBalanceBatchController::class, 'store'])->middleware('mfa');
    Route::post('/opening-balance-batches/{openingBalanceBatch}/invoices', [OpeningBalanceBatchController::class, 'addInvoice'])->middleware('mfa');
    Route::post('/opening-balance-batches/{openingBalanceBatch}/payments', [OpeningBalanceBatchController::class, 'addPayment'])->middleware('mfa');
    Route::post('/opening-balance-batches/{openingBalanceBatch}/payment-allocations', [OpeningBalanceBatchController::class, 'addPaymentAllocation'])->middleware('mfa');
    Route::post('/opening-balance-batches/{openingBalanceBatch}/stock', [OpeningBalanceBatchController::class, 'addStock'])->middleware('mfa');
    Route::post('/opening-balance-batches/{openingBalanceBatch}/document-sequence', [OpeningBalanceBatchController::class, 'initializeDocumentSequence'])->middleware('mfa');
    Route::post('/opening-balance-batches/{openingBalanceBatch}/post', [OpeningBalanceBatchController::class, 'post'])->middleware('mfa');

    Route::get('/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])->middleware('mfa');

    Route::get('/currencies', [CurrencyController::class, 'index']);
    Route::get('/chart-of-accounts', [ChartOfAccountController::class, 'index']);

    // phase1-reports-dashboards (§10/§10.1): every report/dashboard is a
    // read-only query over already-posted data, so none of these routes
    // carry 'mfa' - MFA gates *mutation*, and nothing here mutates.
    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('/reports/ar-aging', [ReportController::class, 'arAging']);
    Route::get('/reports/ap-aging', [ReportController::class, 'apAging']);
    Route::get('/reports/party-ledger', [ReportController::class, 'partyLedger']);
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet']);
    Route::get('/reports/income-statement', [ReportController::class, 'incomeStatement']);
    Route::get('/reports/project-pnl', [ReportController::class, 'projectPnl']);
    Route::get('/reports/general-ledger-detail', [ReportController::class, 'generalLedgerDetail']);
    Route::get('/reports/general-ledger-summary', [ReportController::class, 'generalLedgerSummary']);
    Route::get('/reports/cash-flow-statement', [ReportController::class, 'cashFlowStatement']);

    Route::get('/dashboards/master', [DashboardController::class, 'master']);
    Route::get('/dashboards/crm-sales', [DashboardController::class, 'crmSales']);
    Route::get('/dashboards/inventory', [DashboardController::class, 'inventory']);
    Route::get('/dashboards/procurement', [DashboardController::class, 'procurement']);
    Route::get('/dashboards/finance', [DashboardController::class, 'finance']);
});
