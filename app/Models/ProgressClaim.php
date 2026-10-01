<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Architecture §3.4: wht_amount computed and stored at certification,
 * never recalculated later. status: submitted, certified, disputed,
 * reversed.
 */
class ProgressClaim extends Model
{
    protected $fillable = [
        'tenant_id',
        'subcontract_id',
        'period',
        'amount_claimed_cents',
        'amount_certified_cents',
        'vat_amount_cents',
        'retention_amount_cents',
        'wht_tax_code_id',
        'wht_amount_cents',
        'net_payable_cents',
        'subcontractor_invoice_number',
        'subcontractor_invoice_date',
        'status',
        'journal_entry_id',
        'is_opening_balance',
    ];

    protected $hidden = [
        'amount_claimed_cents', 'amount_certified_cents', 'vat_amount_cents',
        'retention_amount_cents', 'wht_amount_cents', 'net_payable_cents',
    ];

    protected $appends = [
        'amount_claimed', 'amount_certified', 'vat_amount',
        'retention_amount', 'wht_amount', 'net_payable',
    ];

    protected function casts(): array
    {
        return [
            'amount_claimed_cents' => MoneyCast::class,
            'amount_certified_cents' => MoneyCast::class,
            'vat_amount_cents' => MoneyCast::class,
            'retention_amount_cents' => MoneyCast::class,
            'wht_amount_cents' => MoneyCast::class,
            'net_payable_cents' => MoneyCast::class,
            'subcontractor_invoice_date' => 'date',
            'is_opening_balance' => 'boolean',
        ];
    }

    protected function amountClaimed(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_claimed_cents);
    }

    protected function amountCertified(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_certified_cents);
    }

    protected function vatAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->vat_amount_cents);
    }

    protected function retentionAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->retention_amount_cents);
    }

    protected function whtAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->wht_amount_cents);
    }

    protected function netPayable(): Attribute
    {
        return Attribute::make(get: fn () => $this->net_payable_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function subcontract()
    {
        return $this->belongsTo(Subcontract::class);
    }

    public function whtTaxCode()
    {
        return $this->belongsTo(TaxCode::class, 'wht_tax_code_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function retentionAccount()
    {
        return $this->hasOne(RetentionAccount::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
