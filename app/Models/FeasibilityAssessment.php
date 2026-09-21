<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class FeasibilityAssessment extends Model
{
    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'assessed_by',
        'assessed_at',
        'result',
        'notes',
        'conditions',
    ];

    protected function casts(): array
    {
        return ['assessed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function assessedBy()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
