<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const PAYMENT_TERMS = ['credit', 'cash'];

    public const CREDIT_APPROVAL_STATUSES = ['not_required', 'pending', 'approved', 'rejected', 'overridden'];

    public const STATUSES = ['draft', 'raised', 'partially_paid', 'paid', 'written_off'];

    protected $fillable = [
        'tenant_id',
        'document_number',
        'sales_order_id',
        'party_id',
        'invoice_date',
        'posting_date',
        'payment_terms',
        'credit_approval_status',
        'gross_amount_cents',
        'vat_amount_cents',
        'retention_percentage',
        'retention_amount_cents',
        'net_payable_cents',
        'tax_code_id',
        'etr_serial_number',
        'etims_invoice_number',
        'status',
        'journal_entry_id',
        'is_opening_balance',
    ];

    protected $hidden = ['gross_amount_cents', 'vat_amount_cents', 'retention_amount_cents', 'net_payable_cents'];

    protected $appends = ['gross_amount', 'vat_amount', 'retention_amount', 'net_payable'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'posting_date' => 'date',
            'retention_percentage' => 'decimal:4',
            'is_opening_balance' => 'boolean',
            'gross_amount_cents' => MoneyCast::class,
            'vat_amount_cents' => MoneyCast::class,
            'retention_amount_cents' => MoneyCast::class,
            'net_payable_cents' => MoneyCast::class,
        ];
    }

    protected function grossAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->gross_amount_cents);
    }

    protected function vatAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->vat_amount_cents);
    }

    protected function retentionAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->retention_amount_cents);
    }

    protected function netPayable(): Attribute
    {
        return Attribute::make(get: fn () => $this->net_payable_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function lines()
    {
        return $this->morphMany(InvoiceLine::class, 'lineable');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
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
