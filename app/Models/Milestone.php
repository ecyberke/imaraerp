<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Milestone extends Model
{
    public const STATUSES = ['pending', 'utilized', 'signed_off', 'rework_required', 'invoiced', 'closed'];

    protected $fillable = [
        'tenant_id',
        'project_id',
        'sequence',
        'description',
        'billing_amount_cents',
        'billing_locked',
        'status',
    ];

    protected $hidden = ['billing_amount_cents'];

    protected $appends = ['billing_amount'];

    protected function casts(): array
    {
        return [
            'billing_locked' => 'boolean',
            'billing_amount_cents' => MoneyCast::class,
        ];
    }

    protected function billingAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->billing_amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function boqLineAllocations()
    {
        return $this->hasMany(MilestoneBoqLineAllocation::class);
    }

    public function defects()
    {
        return $this->hasMany(Defect::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
