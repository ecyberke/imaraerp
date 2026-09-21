<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class MeasurementSheet extends Model
{
    protected $fillable = [
        'tenant_id',
        'boq_line_id',
        'period',
        'previous_qty',
        'current_qty',
        'cumulative_qty',
        'certified_qty',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'previous_qty' => 'decimal:4',
            'current_qty' => 'decimal:4',
            'cumulative_qty' => 'decimal:4',
            'certified_qty' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function boqLine()
    {
        return $this->belongsTo(BoqLine::class, 'boq_line_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
