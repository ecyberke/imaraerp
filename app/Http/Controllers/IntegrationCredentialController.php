<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Services\IntegrationCredentialService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin-only - these are third-party API secrets (§1.1's secret-management posture applies here same as anywhere else). */
class IntegrationCredentialController extends Controller
{
    public function __construct(private IntegrationCredentialService $credentials) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', IntegrationCredential::class);

        return response()->json(collect(IntegrationCredential::PROVIDERS)
            ->map(fn (string $provider) => $this->credentials->masked($request->user()->tenant, $provider))
            ->values());
    }

    public function update(Request $request, string $provider)
    {
        $this->authorize('create', IntegrationCredential::class);

        if (! in_array($provider, IntegrationCredential::PROVIDERS, true)) {
            abort(404);
        }

        $schema = IntegrationCredential::FIELD_SCHEMA[$provider] ?? [];

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'fields' => ['required', 'array'],
            ...collect($schema)->mapWithKeys(fn ($meta, $key) => ["fields.{$key}" => ['nullable', 'string']])->all(),
        ]);

        if (isset($data['fields']['environment'])) {
            $request->validate(['fields.environment' => [Rule::in(['sandbox', 'production'])]]);
        }

        $this->credentials->set($request->user()->tenant, $provider, $data['fields'], $data['is_active']);

        return response()->json($this->credentials->masked($request->user()->tenant, $provider));
    }
}
