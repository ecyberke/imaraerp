<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * §5.2 state machine: requisitioned -> pending_approval -> approved ->
 * ordered -> partially_received -> received -> quarantined ->
 * (qc_passed -> stocked | qc_failed -> returned). §3.10 names PO as one
 * of AuditLog's observed entities from the start - never actually
 * attached until now (approval-notification-compliance; see this
 * branch's own note on AuditLogObserver being wired to DummyRecord only
 * despite that docblock's broader claim).
 */
#[ObservedBy(AuditLogObserver::class)]
class PurchaseOrder extends Model
{
    public const STATUSES = [
        'requisitioned', 'pending_approval', 'approved', 'ordered',
        'partially_received', 'received', 'quarantined',
        'qc_passed', 'qc_failed', 'stocked', 'returned',
    ];

    protected $fillable = [
        'tenant_id',
        'purchase_requisition_id',
        'party_id',
        'currency_id',
        'exchange_rate',
        'status',
        'approved_by',
        'second_approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return ['exchange_rate' => 'decimal:6', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function purchaseRequisition()
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function secondApprovedBy()
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function grns()
    {
        return $this->hasMany(GoodsReceiptNote::class, 'purchase_order_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * §5.2: 'partially_received' when at least one GRNLine has posted but
     * some PurchaseOrderLines are still short; 'received' when every
     * line's cumulative GRNLine.quantity_received meets or exceeds its
     * quantity_ordered.
     */
    public function refreshReceivingStatus(): void
    {
        $lines = $this->lines()->withSum('grnLines as received_sum', 'quantity_received')->get();

        $anyReceived = false;
        $allFullyReceived = true;

        foreach ($lines as $line) {
            $received = (string) ($line->received_sum ?? 0);
            if (bccomp($received, '0', 4) > 0) {
                $anyReceived = true;
            }
            if (bccomp($received, (string) $line->quantity_ordered, 4) < 0) {
                $allFullyReceived = false;
            }
        }

        if ($allFullyReceived && $anyReceived) {
            $this->update(['status' => 'received']);
        } elseif ($anyReceived) {
            $this->update(['status' => 'partially_received']);
        }
    }
}
