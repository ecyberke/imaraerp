<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class SupplierReturn extends Model
{
    protected $fillable = [
        'tenant_id',
        'item_id',
        'warehouse_id',
        'quantity',
        'unit_cost_cents',
        'already_paid',
        'journal_entry_id',
    ];

    protected $hidden = ['unit_cost_cents'];

    protected $appends = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_cents' => MoneyCast::class,
            'already_paid' => 'boolean',
        ];
    }

    protected function unitCost(): Attribute
    {
        return Attribute::make(get: fn () => $this->unit_cost_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
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
