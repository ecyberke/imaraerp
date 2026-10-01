<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class VariationOrder extends Model
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'executed'];

    protected $fillable = [
        'tenant_id',
        'project_id',
        'variation_number',
        'description',
        'status',
        'amount_delta_cents',
        'approved_by',
        'second_approved_by',
        'submitted_date',
        'approved_date',
        'executed_date',
    ];

    protected $hidden = ['amount_delta_cents'];

    protected $appends = ['amount_delta'];

    protected function casts(): array
    {
        return [
            'submitted_date' => 'datetime',
            'approved_date' => 'datetime',
            'executed_date' => 'datetime',
            'amount_delta_cents' => MoneyCast::class,
        ];
    }

    protected function amountDelta(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_delta_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function lines()
    {
        return $this->hasMany(VariationOrderLine::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function secondApprovedBy()
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    public function reversal()
    {
        return $this->hasOne(VariationOrderReversal::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
