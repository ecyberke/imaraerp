<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $fillable = [
        'tenant_id',
        'lineable_type',
        'lineable_id',
        'description',
        'quantity',
        'unit_price_cents',
        'line_total_cents',
        'vat_amount_cents',
        'source_type',
        'source_id',
    ];

    protected $hidden = ['unit_price_cents', 'line_total_cents', 'vat_amount_cents'];

    protected $appends = ['unit_price', 'line_total', 'vat_amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_cents' => MoneyCast::class,
            'line_total_cents' => MoneyCast::class,
            'vat_amount_cents' => MoneyCast::class,
        ];
    }

    protected function unitPrice(): Attribute
    {
        return Attribute::make(get: fn () => $this->unit_price_cents);
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::make(get: fn () => $this->line_total_cents);
    }

    protected function vatAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->vat_amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lineable()
    {
        return $this->morphTo();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
