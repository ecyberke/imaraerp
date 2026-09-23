<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\DashboardService;
use Illuminate\Http\Request;

/**
 * Each dashboard reuses the closest matching entity's own
 * viewAny ability rather than a dedicated DashboardPolicy per module -
 * same reasoning as ReportController.
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    public function master(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return $this->dashboards->master($request->user()->tenant);
    }

    public function crmSales(Request $request)
    {
        $this->authorize('viewAny', SalesOrder::class);

        return $this->dashboards->crmSales($request->user()->tenant);
    }

    public function inventory(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        return $this->dashboards->inventory($request->user()->tenant);
    }

    public function procurement(Request $request)
    {
        $this->authorize('viewAny', \App\Models\PurchaseOrder::class);

        return $this->dashboards->procurement($request->user()->tenant);
    }

    public function finance(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        return $this->dashboards->finance($request->user()->tenant);
    }
}
