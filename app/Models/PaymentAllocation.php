<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** §3.9: invoice_id = null *is* the Customer Advance - no separate entity. */
class PaymentAllocation extends Model
{
    protected $fillable = [
        'tenant_id',
        'payment_id',
        'invoice_id',
        'amount_allocated_cents',
        'status',
        'is_opening_balance',
    ];

    protected $hidden = ['amount_allocated_cents'];

    protected $appends = ['amount_allocated'];

    protected function casts(): array
    {
        return [
            'is_opening_balance' => 'boolean',
            'amount_allocated_cents' => MoneyCast::class,
        ];
    }

    protected function amountAllocated(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_allocated_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function isAdvance(): bool
    {
        return $this->invoice_id === null;
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
