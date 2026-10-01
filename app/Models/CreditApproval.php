<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CreditApproval extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'overridden'];

    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'invoice_id',
        'requested_amount_cents',
        'approved_by',
        'approved_at',
        'notes',
        'status',
    ];

    protected $hidden = ['requested_amount_cents'];

    protected $appends = ['requested_amount'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'requested_amount_cents' => MoneyCast::class,
        ];
    }

    protected function requestedAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->requested_amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
