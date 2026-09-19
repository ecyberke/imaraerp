<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Category::class);

        return Category::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Category::class);

        // exists: is tenant-scoped explicitly - the bare rule checks
        // existence globally, which would let a request reference another
        // tenant's row by guessing its id.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'valuation_method' => ['required', 'in:fifo,weighted_average,standard_cost'],
        ]);

        $category = Category::create([...$data, 'tenant_id' => $request->user()->tenant_id]);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        $this->authorize('view', $category);

        return $category;
    }

    public function update(Request $request, Category $category)
    {
        $this->authorize('update', $category);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'valuation_method' => ['sometimes', 'in:fifo,weighted_average,standard_cost'],
        ]);

        $category->update($data);

        return $category;
    }

    public function destroy(Category $category)
    {
        $this->authorize('delete', $category);

        $category->delete();

        return response()->noContent();
    }
}
