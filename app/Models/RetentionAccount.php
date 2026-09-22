<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class RetentionAccount extends Model
{
    public const DIRECTIONS = ['receivable', 'payable'];

    protected $fillable = [
        'tenant_id',
        'contract_type',
        'contract_id',
        'party_id',
        'direction',
        'invoice_id',
        'progress_claim_id',
        'amount_cents',
        'released_amount_cents',
        'is_opening_balance',
    ];

    protected $hidden = ['amount_cents', 'released_amount_cents'];

    protected $appends = ['amount', 'released_amount', 'balance'];

    protected function casts(): array
    {
        return [
            'is_opening_balance' => 'boolean',
            'amount_cents' => MoneyCast::class,
            'released_amount_cents' => MoneyCast::class,
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected function releasedAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->released_amount_cents);
    }

    /** §3.9: "balance (computed)" - never stored. */
    protected function balance(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents->sub($this->released_amount_cents));
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function progressClaim()
    {
        return $this->belongsTo(ProgressClaim::class);
    }

    public function releases()
    {
        return $this->hasMany(RetentionRelease::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
