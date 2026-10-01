<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use Illuminate\Http\Request;

class AssetCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', AssetCategory::class);

        return AssetCategory::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', AssetCategory::class);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        return response()->json(AssetCategory::create([...$data, 'tenant_id' => $request->user()->tenant_id]), 201);
    }
}
