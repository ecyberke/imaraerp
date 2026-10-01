<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class AssetDisposal extends Model
{
    public const DISPOSAL_TYPES = ['sold', 'scrapped', 'written_off'];

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'disposal_date',
        'disposal_type',
        'sale_proceeds_cents',
        'sold_on_credit',
        'net_book_value_at_disposal_cents',
        'gain_loss_amount_cents',
        'status',
        'journal_entry_id',
    ];

    protected $hidden = ['sale_proceeds_cents', 'net_book_value_at_disposal_cents', 'gain_loss_amount_cents'];

    protected $appends = ['sale_proceeds', 'net_book_value_at_disposal', 'gain_loss_amount'];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'sold_on_credit' => 'boolean',
            'sale_proceeds_cents' => MoneyCast::class,
            'net_book_value_at_disposal_cents' => MoneyCast::class,
            'gain_loss_amount_cents' => MoneyCast::class,
        ];
    }

    protected function saleProceeds(): Attribute
    {
        return Attribute::make(get: fn () => $this->sale_proceeds_cents);
    }

    protected function netBookValueAtDisposal(): Attribute
    {
        return Attribute::make(get: fn () => $this->net_book_value_at_disposal_cents);
    }

    protected function gainLossAmount(): Attribute
    {
        return Attribute::make(get: fn () => $this->gain_loss_amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
