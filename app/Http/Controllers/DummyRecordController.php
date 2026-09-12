<?php

namespace App\Http\Controllers;

use App\Models\DummyRecord;
use App\Services\AccountingPeriodResolver;
use Illuminate\Http\Request;

class DummyRecordController extends Controller
{
    public function __construct(private AccountingPeriodResolver $periods)
    {
    }

    public function store(Request $request)
    {
        $this->authorize('create', DummyRecord::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Server-side input validation policy (§1.1): dates are never
            // trusted blindly even for a throwaway entity - this mirrors
            // what every real financial-entity request will enforce.
            'record_date' => ['nullable', 'date'],
        ]);

        $intendedDate = isset($data['record_date'])
            ? \Carbon\Carbon::parse($data['record_date'])
            : \App\Support\BusinessTime::today();

        $resolution = $this->periods->resolve($request->user()->tenant, $intendedDate);

        $record = DummyRecord::create([
            'tenant_id' => $request->user()->tenant_id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'record_date' => $resolution->postingDate,
            'accounting_period_id' => $resolution->period->id,
            'original_intended_posting_date' => $resolution->originalIntendedPostingDate,
        ]);

        return response()->json([
            'id' => $record->id,
            'name' => $record->name,
            'record_date' => $record->record_date->toDateString(),
            'accounting_period_id' => $record->accounting_period_id,
            'original_intended_posting_date' => $record->original_intended_posting_date?->toDateString(),
        ], 201);
    }

    public function show(DummyRecord $dummyRecord)
    {
        $this->authorize('view', $dummyRecord);

        return response()->json(['id' => $dummyRecord->id, 'name' => $dummyRecord->name]);
    }
}
