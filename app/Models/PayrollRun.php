<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class PayrollRun extends Model
{
    public const STATUSES = ['draft', 'processing', 'approved', 'paid'];

    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'status',
        'run_date',
        'approved_by',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'run_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
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
