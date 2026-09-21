<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    protected $fillable = [
        'tenant_id',
        'demand_trigger_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function demandTrigger()
    {
        return $this->belongsTo(DemandTrigger::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
