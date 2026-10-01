<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Item::class);

        return Item::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Item::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'type' => ['required', 'in:raw_material,finished_good'],
            'uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)],
            // A quantity field - rejects negative outright; zero is valid
            // here (means "never auto-trigger reorder"), unlike a
            // transactional quantity field, so the zero/negative rule's
            // "except variation_type = omission" carve-out doesn't apply
            // the same way - this is a threshold, not a quantity being
            // moved.
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertStandardCostOnlyOnStandardCostCategory($data);

        $item = Item::create([
            'tenant_id' => $tenantId,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category_id' => $data['category_id'],
            'type' => $data['type'],
            'uom_id' => $data['uom_id'],
            'reorder_level' => $data['reorder_level'] ?? 0,
            'standard_cost_cents' => isset($data['standard_cost']) ? Money::fromMajor($data['standard_cost']) : null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($item, 201);
    }

    public function show(Item $item)
    {
        $this->authorize('view', $item);

        return $item;
    }

    public function update(Request $request, Item $item)
    {
        $this->authorize('update', $item);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'type' => ['sometimes', 'in:raw_material,finished_good'],
            'uom_id' => ['sometimes', 'integer', Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)],
            'reorder_level' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'standard_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Only worth checking if a non-null standard_cost is actually
        // being set (or changed) on this request - clearing it to null,
        // or leaving it untouched, never needs the category check.
        if (! empty($data['standard_cost'] ?? null)) {
            $this->assertStandardCostOnlyOnStandardCostCategory([
                'category_id' => $data['category_id'] ?? $item->category_id,
                'standard_cost' => $data['standard_cost'],
            ]);
        }

        if (array_key_exists('standard_cost', $data)) {
            $data['standard_cost_cents'] = $data['standard_cost'] !== null ? Money::fromMajor($data['standard_cost']) : null;
            unset($data['standard_cost']);
        }

        $item->update($data);

        return $item;
    }

    public function destroy(Item $item)
    {
        $this->authorize('delete', $item);

        $item->delete();

        return response()->noContent();
    }

    /**
     * §3.1: standard_cost "is only meaningful when Category.valuation_method
     * = standard_cost" - guarded here so an Item never carries a standard
     * cost that has nothing to mean anything against.
     */
    private function assertStandardCostOnlyOnStandardCostCategory(array $data): void
    {
        if (! isset($data['standard_cost']) || ! isset($data['category_id'])) {
            return;
        }

        $category = Category::withoutGlobalScopes()->find($data['category_id']);

        if ($category && $category->valuation_method !== 'standard_cost') {
            throw ValidationException::withMessages([
                'standard_cost' => ["standard_cost can only be set on items whose category uses standard_cost valuation."],
            ]);
        }
    }
}
