<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class StatutoryRemittance extends Model
{
    public const AUTHORITIES = ['KRA-PAYE', 'NSSF', 'SHIF', 'HELB', 'NITA', 'KRA-WHT'];

    public const AUTHORITY_ACCOUNTS = [
        'KRA-PAYE' => 'PAYE Payable',
        'NSSF' => 'NSSF Payable',
        'SHIF' => 'SHIF Payable',
        'HELB' => 'HELB Payable',
        'NITA' => 'NITA Payable',
        'KRA-WHT' => 'WHT Payable',
    ];

    protected $fillable = [
        'tenant_id',
        'period',
        'authority',
        'amount_cents',
        'reference_number',
        'paid_at',
        'status',
        'journal_entry_id',
    ];

    protected $hidden = ['amount_cents'];

    protected $appends = ['amount'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'paid_at' => 'datetime',
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

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
