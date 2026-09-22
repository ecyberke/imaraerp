<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WriteOff extends Model
{
    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'progress_claim_id',
        'amount_cents',
        'reason',
        'approved_by',
        'journal_entry_id',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return ['amount_cents' => MoneyCast::class];
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

    public function progressClaim()
    {
        return $this->belongsTo(ProgressClaim::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
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
