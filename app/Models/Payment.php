<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public const DIRECTIONS = ['receipt', 'disbursement'];

    public const METHODS = ['cash', 'bank_transfer', 'cheque', 'mpesa'];

    protected $fillable = [
        'tenant_id',
        'party_id',
        'direction',
        'reference_type',
        'reference_id',
        'settlement_exchange_rate',
        'amount_cents',
        'method',
        'mpesa_reference',
        'mpesa_reconciliation_status',
        'bank_account_id',
        'tax_code_id',
        'wht_amount_cents',
        'received_at',
        'posting_date',
        'is_opening_balance',
    ];

    protected $hidden = ['amount_cents', 'wht_amount_cents'];

    protected $appends = ['amount', 'wht_amount'];

    protected function casts(): array
    {
        return [
            'settlement_exchange_rate' => 'decimal:6',
            'received_at' => 'datetime',
            'posting_date' => 'date',
            'is_opening_balance' => 'boolean',
            'amount_cents' => MoneyCast::class,
            'wht_amount_cents' => MoneyCast::class,
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected function whtAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->wht_amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
