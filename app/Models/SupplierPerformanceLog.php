<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class SupplierPerformanceLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'party_id',
        'po_id',
        'event_type',
        'notes',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
