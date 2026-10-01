<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    public const STATUSES = ['draft', 'approved', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'demand_trigger_id',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function demandTrigger()
    {
        return $this->belongsTo(DemandTrigger::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
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
