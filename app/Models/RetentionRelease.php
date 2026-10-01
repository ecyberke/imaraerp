<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** §3.10 names RetentionRelease as one of AuditLog's observed entities from the start - see PurchaseOrder's own note on why this wasn't actually true until now. */
#[ObservedBy(AuditLogObserver::class)]
class RetentionRelease extends Model
{
    public const STAGES = ['practical_completion', 'dlp_end'];

    public const STATUSES = ['pending', 'blocked_defects', 'blocked_client_signoff', 'blocked_dispute', 'ready', 'released'];

    protected $fillable = [
        'tenant_id',
        'retention_account_id',
        'stage',
        'amount_cents',
        'released_at',
        'status',
        'block_reason',
        'early_release_reason',
        'journal_entry_id',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'amount_cents' => MoneyCast::class,
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn () => $this->amount_cents);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function retentionAccount()
    {
        return $this->belongsTo(RetentionAccount::class);
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
