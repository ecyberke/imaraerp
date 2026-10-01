<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class EquipmentHireContract extends Model
{
    protected $fillable = [
        'tenant_id',
        'party_id',
        'description',
        'project_id',
        'hire_rate_cents',
        'hire_start_date',
        'hire_end_date',
        'invoiced_days',
        'hire_invoice_mismatch',
    ];

    protected $hidden = ['hire_rate_cents'];

    protected $appends = ['hire_rate', 'expected_days'];

    protected function casts(): array
    {
        return [
            'hire_start_date' => 'date',
            'hire_end_date' => 'date',
            'hire_invoice_mismatch' => 'boolean',
            'hire_rate_cents' => MoneyCast::class,
        ];
    }

    protected function hireRate(): Attribute
    {
        return Attribute::make(get: fn () => $this->hire_rate_cents);
    }

    /**
     * §3.12: "expected_days (computed from the assignment dates)."
     * Open-ended contracts (no hire_end_date yet) have no expected day
     * count to compare an invoice against until one is set.
     */
    protected function expectedDays(): Attribute
    {
        return Attribute::make(get: function () {
            if (! $this->hire_end_date) {
                return null;
            }

            return (int) $this->hire_start_date->diffInDays($this->hire_end_date) + 1;
        });
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
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
