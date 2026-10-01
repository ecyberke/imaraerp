<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptNote extends Model
{
    protected $table = 'goods_receipt_notes';

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'quarantine_status',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(GRNLine::class, 'grn_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * §3.3: "StockQuarantine.qc_status is a denormalized summary...
     * never the source of truth if they disagree" - same principle
     * applied one level up: this is a summary of the GRN's own lines'
     * quarantine_status, recomputed whenever a line's QC result changes.
     */
    public function refreshQuarantineStatus(): void
    {
        $statuses = $this->lines()->pluck('quarantine_status')->unique();

        $summary = match (true) {
            $statuses->count() === 1 => $statuses->first(),
            $statuses->contains('pending') => 'pending',
            default => 'mixed',
        };

        $this->update(['quarantine_status' => $summary]);
    }
}
