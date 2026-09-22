<?php

namespace App\Http\Controllers;

use App\Models\RetentionAccount;
use App\Models\RetentionRelease;
use App\Services\RetentionReleaseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RetentionReleaseController extends Controller
{
    public function __construct(private RetentionReleaseService $releases) {}

    public function store(Request $request)
    {
        $this->authorize('create', RetentionRelease::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'retention_account_id' => ['required', 'integer', Rule::exists('retention_accounts', 'id')->where('tenant_id', $tenantId)],
            'stage' => ['required', 'string', Rule::in(RetentionRelease::STAGES)],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $account = RetentionAccount::where('tenant_id', $tenantId)->findOrFail($data['retention_account_id']);

        $release = $this->releases->request($account, $data['stage'], (string) $data['amount']);

        return response()->json($release, 201);
    }

    public function markReady(RetentionRelease $retentionRelease)
    {
        $this->authorize('update', $retentionRelease);

        return $this->releases->markReady($retentionRelease);
    }

    public function release(Request $request, RetentionRelease $retentionRelease)
    {
        $this->authorize('update', $retentionRelease);

        $data = $request->validate([
            'early_release_reason' => ['nullable', 'string'],
        ]);

        $release = $this->releases->release($retentionRelease, $request->user(), $data['early_release_reason'] ?? null);

        return response()->json($release);
    }
}
