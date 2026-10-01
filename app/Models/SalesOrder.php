<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * §3.2/§5.1: "Quotation / SalesOrder" is one continuous document -
 * status spans both the pre-approval quotation stage and the
 * post-approval sales-order stage, there is no separate table/conversion
 * step.
 */
class SalesOrder extends Model
{
    public const SUPPLY_PATHS = ['direct_sale', 'manufacture_for_sale', 'project', 'manufacture_for_project'];

    public const INVOICE_POLICIES = ['on_order', 'on_delivery', 'on_milestone'];

    /**
     * §5.1's exact diagram: draft -> feasibility_check ->
     * (rejected [end] | renegotiating -> feasibility_check) -> approved
     * -> awaiting_fg -> in_fulfillment -> partially_delivered ->
     * delivered -> billed -> closed. Project/Manufacture-for-Project
     * never reach partially_delivered/delivered/billed - closed is
     * gated on Project.status=closed instead (see canClose()).
     */
    public const STATUSES = [
        'draft', 'feasibility_check', 'rejected', 'renegotiating',
        'approved', 'awaiting_fg', 'in_fulfillment',
        'partially_delivered', 'delivered', 'billed', 'closed',
    ];

    protected $fillable = [
        'tenant_id',
        'document_number',
        'lead_id',
        'party_id',
        'project_id',
        'boq_id',
        'supply_path',
        'feasibility_status',
        'invoice_policy',
        'status',
        'currency_id',
        'exchange_rate',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'approved_at',
    ];

    protected $hidden = ['subtotal_cents', 'tax_cents', 'total_cents'];

    protected $appends = ['subtotal', 'tax', 'total'];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'approved_at' => 'datetime',
            'subtotal_cents' => MoneyCast::class,
            'tax_cents' => MoneyCast::class,
            'total_cents' => MoneyCast::class,
        ];
    }

    protected function subtotal(): Attribute
    {
        return Attribute::make(get: fn () => $this->subtotal_cents);
    }

    protected function tax(): Attribute
    {
        return Attribute::make(get: fn () => $this->tax_cents);
    }

    protected function total(): Attribute
    {
        return Attribute::make(get: fn () => $this->total_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function boq()
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function feasibilityAssessments()
    {
        return $this->hasMany(FeasibilityAssessment::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * §5.1: Direct Sale/Manufacture-for-Sale follow the drawn state
     * machine path unconditionally; Project/Manufacture-for-Project gate
     * `closed` on Project.status=closed instead. Project doesn't exist
     * yet (forward reference), so this only asserts the gate is refused
     * when project_id is unset - the actual Project.status check is
     * wired in once projects-milestones-ui lands.
     */
    public function canClose(): bool
    {
        if (in_array($this->supply_path, ['project', 'manufacture_for_project'], true)) {
            return $this->project_id !== null;
        }

        return true;
    }
}
