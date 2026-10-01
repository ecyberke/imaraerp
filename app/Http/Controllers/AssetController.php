<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetDisposal;
use App\Models\User;
use App\Services\AssetDisposalService;
use App\Services\AssetRevaluationService;
use App\Services\AssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function __construct(
        private AssetService $assets,
        private AssetRevaluationService $revaluations,
        private AssetDisposalService $disposals,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        return Asset::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Asset::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'asset_number' => ['required', 'string', 'max:255', Rule::unique('assets', 'asset_number')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->where('tenant_id', $tenantId)],
            'asset_type' => ['required', 'string', Rule::in(Asset::ASSET_TYPES)],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
            'custodian_party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'date_of_purchase' => ['required', 'date'],
            'purchase_cost' => ['required', 'numeric', 'gt:0'],
            'residual_value' => ['nullable', 'numeric', 'gte:0'],
            'useful_life_years' => ['required', 'integer', 'min:1'],
            'depreciation_method' => ['required', 'string', Rule::in(Asset::DEPRECIATION_METHODS)],
            'warranty_years' => ['nullable', 'integer', 'min:0'],
        ]);

        $asset = $this->assets->acquire($request->user()->tenant, $data);

        return response()->json($asset, 201);
    }

    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);

        return $asset->load('category', 'custodian', 'components', 'assignments', 'revaluations', 'disposal');
    }

    public function updateLifespan(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'useful_life_years' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json($this->assets->updateLifespan($asset, $data['useful_life_years'], $data['reason']));
    }

    public function updateStatus(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $data = $request->validate(['status' => ['required', 'string', Rule::in(['in_use', 'under_maintenance'])]]);

        return response()->json($this->assets->updateStatus($asset, $data['status']));
    }

    public function revalue(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'new_valuation' => ['required', 'numeric', 'gt:0'],
            'new_residual_value' => ['nullable', 'numeric', 'gte:0'],
            'new_useful_life_years' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $revaluation = $this->revaluations->revalue(
            $asset,
            (string) $data['new_valuation'],
            isset($data['new_residual_value']) ? (string) $data['new_residual_value'] : null,
            $data['new_useful_life_years'] ?? null,
            $data['reason'],
            $user,
        );

        return response()->json($revaluation, 201);
    }

    public function dispose(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'disposal_type' => ['required', 'string', Rule::in(AssetDisposal::DISPOSAL_TYPES)],
            'sale_proceeds' => ['nullable', 'numeric', 'gte:0'],
            'sold_on_credit' => ['nullable', 'boolean'],
        ]);

        $disposal = $this->disposals->dispose(
            $asset,
            $data['disposal_type'],
            isset($data['sale_proceeds']) ? (string) $data['sale_proceeds'] : null,
            $data['sold_on_credit'] ?? false,
        );

        return response()->json($disposal, 201);
    }
}
