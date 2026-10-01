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
     * types subtract. 'adjustment' and 'return' are the two types whose
     * quantity itself carries the sign, absent from these maps on
     * purpose - StockValuationService applies the stored quantity's own
     * sign for them instead of a fixed direction.
     *
     * 'return' is genuinely bidirectional and the doc doesn't disambiguate:
     * a "surplus return" (§6 - site returns excess RM to the warehouse)
     * is an increase, but a post-acceptance supplier return (§3.4/§7 -
     * goods that already passed QC, returned to the supplier later) is a
     * decrease. procurement's own supplier-return flow is what surfaced
     * this - inventory-core originally classified 'return' as always-IN,
     * which this corrects. Flagging the read (signed like 'adjustment')
     * rather than silently picking one fixed direction.
     */
    public const IN_TYPES = ['receipt', 'transfer_in', 'production_output'];

    public const OUT_TYPES = ['issue', 'transfer_out', 'production_consumption', 'scrap'];

    public const SIGNED_TYPES = ['adjustment', 'return'];

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
        'is_opening_balance',
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
            'is_opening_balance' => 'boolean',
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
        if (in_array($this->movement_type, self::SIGNED_TYPES, true)) {
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
