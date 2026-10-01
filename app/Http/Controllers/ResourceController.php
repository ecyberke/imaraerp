<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Resource::class);

        return Resource::where('tenant_id', $request->user()->tenant_id)->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Resource::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(Resource::TYPES)],
            'party_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('tenant_id', $tenantId)],
            'skill_category' => ['nullable', 'string', 'max:255'],
        ]);

        $resource = Resource::create([...$data, 'tenant_id' => $tenantId]);

        return response()->json($resource, 201);
    }

    public function show(Resource $resource)
    {
        $this->authorize('view', $resource);

        return $resource->load('assignments');
    }
}
