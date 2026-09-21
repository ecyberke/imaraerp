<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BillOfMaterialController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DemandTriggerController;
use App\Http\Controllers\DummyRecordController;
use App\Http\Controllers\GoodsReceiptNoteController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LandedCostController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\ProgressClaimController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\StockAvailabilityController;
use App\Http\Controllers\StockReceivingController;
use App\Http\Controllers\StockReservationController;
use App\Http\Controllers\SubcontractController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\UnitOfMeasureController;
use App\Http\Controllers\WarehouseController;
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

    Route::post('/subcontracts', [SubcontractController::class, 'store'])->middleware('mfa');
    Route::get('/subcontracts/{subcontract}', [SubcontractController::class, 'show']);
    Route::post('/subcontracts/{subcontract}/progress-claims', [ProgressClaimController::class, 'store'])->middleware('mfa');
    Route::get('/progress-claims/{progressClaim}', [ProgressClaimController::class, 'show']);
    Route::post('/progress-claims/{progressClaim}/certify', [ProgressClaimController::class, 'certify'])->middleware('mfa');
    Route::post('/progress-claims/{progressClaim}/reverse', [ProgressClaimController::class, 'reverse'])->middleware('mfa');

    Route::post('/supplier-payments', [SupplierPaymentController::class, 'store'])->middleware('mfa');
    Route::post('/supplier-payments/fx-settlement', [SupplierPaymentController::class, 'storeFxSettlement'])->middleware('mfa');
    Route::post('/supplier-returns', [SupplierReturnController::class, 'store'])->middleware('mfa');
});
