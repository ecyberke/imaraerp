<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceiptNote;
use App\Models\LandedCost;
use App\Services\LandedCostAllocationService;
use App\Support\Money;
use Illuminate\Http\Request;

class LandedCostController extends Controller
{
    public function __construct(private LandedCostAllocationService $allocation)
    {
    }

    public function store(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $this->authorize('create', LandedCost::class);

        $data = $request->validate([
            'cost_type' => ['required', 'in:freight,duty,clearing,insurance'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $landedCost = $this->allocation->allocate($goodsReceiptNote, $data['cost_type'], Money::fromMajor($data['amount']));

        return response()->json($landedCost, 201);
    }
}
