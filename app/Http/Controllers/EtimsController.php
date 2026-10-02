<?php

namespace App\Http\Controllers;

use App\Models\EtimsItemClassification;
use App\Models\IntegrationCredential;
use App\Services\EtimsService;
use Illuminate\Http\Request;

/** Admin-only - same bar as IntegrationCredentialController, these are device-level KRA config actions. */
class EtimsController extends Controller
{
    public function __construct(private EtimsService $etims) {}

    public function initializeDevice(Request $request)
    {
        $this->authorize('create', IntegrationCredential::class);

        return response()->json($this->etims->initializeDevice($request->user()->tenant));
    }

    public function syncItemClassifications(Request $request)
    {
        $this->authorize('create', IntegrationCredential::class);

        return response()->json($this->etims->syncItemClassifications($request->user()->tenant));
    }

    public function listItemClassifications(Request $request)
    {
        $this->authorize('viewAny', IntegrationCredential::class);

        return EtimsItemClassification::where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get();
    }
}
