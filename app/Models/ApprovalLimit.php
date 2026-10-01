<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ApprovalLimit extends Model
{
    public const ENTITY_TYPES = ['purchase_requisition', 'purchase_order', 'credit_approval', 'variation_order'];

    protected $fillable = [
        'tenant_id',
        'role_id',
        'entity_type',
        'max_amount_cents',
        'requires_second_approval_above_cents',
        'second_approver_role_id',
    ];

    protected $hidden = ['max_amount_cents', 'requires_second_approval_above_cents'];

    protected $appends = ['max_amount', 'requires_second_approval_above'];

    protected function casts(): array
    {
        return [
            'max_amount_cents' => MoneyCast::class,
            'requires_second_approval_above_cents' => MoneyCast::class,
        ];
    }

    protected function maxAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->max_amount_cents);
    }

    protected function requiresSecondApprovalAbove(): Attribute
    {
        return Attribute::make(get: fn () => $this->requires_second_approval_above_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function secondApproverRole()
    {
        return $this->belongsTo(Role::class, 'second_approver_role_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
