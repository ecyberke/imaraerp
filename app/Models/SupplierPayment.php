<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $fillable = [
        'tenant_id',
        'party_id',
        'reference_type',
        'reference_id',
        'amount_cents',
        'settlement_exchange_rate',
        'wht_withheld_cents',
        'method',
        'paid_at',
        'journal_entry_id',
    ];

    protected $hidden = ['amount_cents', 'wht_withheld_cents'];

    protected $appends = ['amount', 'wht_withheld'];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'wht_withheld_cents' => MoneyCast::class,
            'settlement_exchange_rate' => 'decimal:6',
            'paid_at' => 'datetime',
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected function whtWithheld(): Attribute
    {
        return Attribute::make(get: fn () => $this->wht_withheld_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
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
