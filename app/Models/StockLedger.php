<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Architecture §3.3: append-only physical movement log. Never updated or
 * deleted - no UPDATED_AT, enforced by convention (no controller/service
 * anywhere calls ->update()/->delete() on this model), same pattern as
 * AuditLog/JournalEntry.
 */
class StockLedger extends Model
{
    const UPDATED_AT = null;

    /**
     * §3.3's exhaustive movement_type list - IN types add to on-hand, OUT
     * types subtract. 'adjustment' is the one type whose quantity itself
     * carries the sign (a correction can go either direction); it's
     * absent from this map on purpose - StockValuationService applies the
     * stored quantity's own sign for it instead of a fixed direction.
     */
    public const IN_TYPES = ['receipt', 'transfer_in', 'production_output', 'return'];

    public const OUT_TYPES = ['issue', 'transfer_out', 'production_consumption', 'scrap'];

    protected $table = 'stock_ledger';

    protected $fillable = [
        'tenant_id',
        'item_id',
        'warehouse_id',
        'location_id',
        'movement_type',
        'quantity',
        'unit_cost_cents',
        'reference_type',
        'reference_id',
    ];

    protected $hidden = [
        'unit_cost_cents',
    ];

    protected $appends = [
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_cents' => MoneyCast::class,
        ];
    }

    protected function unitCost(): Attribute
    {
        return Attribute::make(get: fn () => $this->unit_cost_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /** The signed quantity this row contributes to on-hand stock. */
    public function signedQuantity(): string
    {
        if ($this->movement_type === 'adjustment') {
            return (string) $this->quantity;
        }

        if (in_array($this->movement_type, self::OUT_TYPES, true)) {
            return bcmul((string) $this->quantity, '-1', 4);
        }

        return (string) $this->quantity;
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
