<?php

namespace App\Http\Controllers;

use App\Models\ComplianceDocument;
use App\Models\Party;
use App\Services\ComplianceDocumentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComplianceDocumentController extends Controller
{
    public function __construct(private ComplianceDocumentService $complianceDocuments) {}

    public function indexForParty(Party $party)
    {
        $this->authorize('viewAny', ComplianceDocument::class);

        return ComplianceDocument::where('party_id', $party->id)->get()
            ->map(fn (ComplianceDocument $doc) => $this->complianceDocuments->refreshStatus($doc));
    }

    public function store(Request $request, Party $party)
    {
        $this->authorize('create', ComplianceDocument::class);

        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(ComplianceDocument::DOCUMENT_TYPES)],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'file_path' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->complianceDocuments->create($party, $data), 201);
    }
}
