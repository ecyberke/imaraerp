<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use Illuminate\Http\Request;

/**
 * Read-only - Currency rows are seeded automatically on tenant creation
 * (Tenant::seedDefaultCurrency(), currently just KES) and no branch
 * through phase1-reports-dashboards ever exposed a way to list them.
 * phase1-screens needs this to populate a currency picker on the
 * Quotation form, so this closes that gap now.
 */
class CurrencyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Currency::class);

        return Currency::where('tenant_id', $request->user()->tenant_id)->get();
    }
}
