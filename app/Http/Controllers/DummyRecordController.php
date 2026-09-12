<?php

namespace App\Http\Controllers;

use App\Models\DummyRecord;
use Illuminate\Http\Request;

class DummyRecordController extends Controller
{
    public function store(Request $request)
    {
        $this->authorize('create', DummyRecord::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $record = DummyRecord::create([
            'tenant_id' => $request->user()->tenant_id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
        ]);

        return response()->json(['id' => $record->id, 'name' => $record->name], 201);
    }

    public function show(DummyRecord $dummyRecord)
    {
        $this->authorize('view', $dummyRecord);

        return response()->json(['id' => $dummyRecord->id, 'name' => $dummyRecord->name]);
    }
}
