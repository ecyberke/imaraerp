<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    public const REASONS = ['dispute', 'returned_materials', 'price_adjustment', 'overbilling_correction', 'retention_release'];

    protected $fillable = [
        'tenant_id',
        'document_number',
        'invoice_id',
        'amount_cents',
        'reason',
        'etr_serial_number',
        'etims_invoice_number',
        'note_date',
        'posting_date',
        'status',
        'journal_entry_id',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'posting_date' => 'date',
            'amount_cents' => MoneyCast::class,
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines()
    {
        return $this->morphMany(InvoiceLine::class, 'lineable');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
