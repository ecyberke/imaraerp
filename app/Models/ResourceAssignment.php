<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class ResourceAssignment extends Model
{
    public const STATUSES = ['scheduled', 'active', 'completed', 'cancelled'];

    protected $fillable = [
        'tenant_id',
        'resource_id',
        'project_id',
        'sales_order_id',
        'block_start_date',
        'block_end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'block_start_date' => 'date',
            'block_end_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
