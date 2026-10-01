<?php

namespace App\Http\Controllers;

use App\Models\DemandTrigger;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\DemandTriggerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DemandTriggerController extends Controller
{
    public function __construct(private DemandTriggerService $demand)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', DemandTrigger::class);

        return DemandTrigger::where('tenant_id', $request->user()->tenant_id)->get();
    }

    /**
     * Simulates the scheduled reorder-level job (§3.4/§6) for a single
     * item/warehouse, on demand - a real recurring Schedule::command()
     * job (the withoutOverlapping() pattern platform-foundation
     * established) is an operational concern for later, not this
     * branch's own entity/posting work.
     */
    public function check(Request $request)
    {
        $this->authorize('create', DemandTrigger::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ]);

        $item = Item::where('tenant_id', $tenantId)->findOrFail($data['item_id']);
        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        $trigger = $this->demand->checkAndRaise($item, $warehouse);

        // wasRecentlyCreated distinguishes "a new trigger was raised" from
        // "an already-open trigger was found and deduplicated against" -
        // the bare truthiness of $trigger can't tell those apart, since
        // both return a real DemandTrigger instance.
        $isNew = $trigger?->wasRecentlyCreated ?? false;

        return response()->json($trigger, $isNew ? 201 : 200);
    }
}
