<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Services\StockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockReservationController extends Controller
{
    public function __construct(private StockAvailabilityService $availability)
    {
    }

    public function store(Request $request)
    {
        $this->authorize('create', StockReservation::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'reserve_type' => ['required', 'in:soft,hard'],
            // A transactional quantity - rejects zero/negative outright (§1.1).
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference_type' => ['nullable', 'string', 'max:100'],
            'reference_id' => ['nullable', 'integer'],
        ]);

        $item = Item::where('tenant_id', $tenantId)->findOrFail($data['item_id']);
        $warehouse = Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);

        try {
            $reservation = $this->availability->reserve(
                $item, $warehouse, (string) $data['quantity'], $data['reserve_type'],
                $data['reference_type'] ?? null, $data['reference_id'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'requested' => $e->requested,
                'available' => $e->available,
            ], 409);
        }

        return response()->json($reservation, 201);
    }

    public function show(StockReservation $stockReservation)
    {
        $this->authorize('view', $stockReservation);

        return $stockReservation;
    }
}
