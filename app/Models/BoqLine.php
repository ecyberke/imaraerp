<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class BoqLine extends Model
{
    protected $table = 'boq_lines';

    public const LINE_TYPES = ['measured', 'provisional_sum', 'prime_cost'];

    protected $fillable = [
        'tenant_id',
        'boq_id',
        'section_id',
        'item_id',
        'description',
        'unit',
        'quantity',
        'rate_cents',
        'amount_cents',
        'line_type',
    ];

    protected $hidden = ['rate_cents', 'amount_cents'];

    protected $appends = ['rate', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate_cents' => MoneyCast::class,
            'amount_cents' => MoneyCast::class,
        ];
    }

    protected function rate(): Attribute
    {
        return Attribute::make(get: fn () => $this->rate_cents);
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function boq()
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function section()
    {
        return $this->belongsTo(BoqSection::class, 'section_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function measurementSheets()
    {
        return $this->hasMany(MeasurementSheet::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
