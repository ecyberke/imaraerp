<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CapitalMovement extends Model
{
    public const DIRECTIONS = ['loan_received', 'loan_repaid', 'capital_injected', 'capital_withdrawn'];

    protected $fillable = [
        'tenant_id',
        'direction',
        'amount_cents',
        'party_id',
        'movement_date',
        'journal_entry_id',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
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
