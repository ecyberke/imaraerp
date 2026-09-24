<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;

/**
 * Read-only - the seeded Chart of Accounts (ChartOfAccountsSeeder) had
 * no way to be listed via the API until now. Needed by phase1-screens'
 * Bank Account form (gl_account_id) and useful for any future screen
 * that needs to reference a real account by name.
 */
class ChartOfAccountController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ChartOfAccount::class);

        return ChartOfAccount::where('tenant_id', $request->user()->tenant_id)->orderBy('code')->get();
    }
}
