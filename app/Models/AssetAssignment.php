<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class AssetAssignment extends Model
{
    public const STATUSES = ['active', 'released'];

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'project_id',
        'assigned_date',
        'released_date',
        'internal_daily_rate_cents',
        'meter_reading_start',
        'meter_reading_end',
        'status',
    ];

    protected $hidden = ['internal_daily_rate_cents'];

    protected $appends = ['internal_daily_rate'];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'released_date' => 'date',
            'internal_daily_rate_cents' => MoneyCast::class,
            'meter_reading_start' => 'decimal:2',
            'meter_reading_end' => 'decimal:2',
        ];
    }

    protected function internalDailyRate(): Attribute
    {
        return Attribute::make(get: fn () => $this->internal_daily_rate_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
