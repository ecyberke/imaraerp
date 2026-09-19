<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Warehouse::class);

        return Warehouse::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Warehouse::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $warehouse = Warehouse::create([...$data, 'tenant_id' => $request->user()->tenant_id]);

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse)
    {
        $this->authorize('view', $warehouse);

        return $warehouse;
    }
}
