<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(AuditLogObserver::class)]
class ComplianceDocument extends Model
{
    public const DOCUMENT_TYPES = ['insurance', 'lien_waiver', 'safety_cert', 'tax_compliance'];

    public const STATUSES = ['valid', 'expiring_soon', 'expired'];

    protected $fillable = [
        'tenant_id',
        'party_id',
        'document_type',
        'issue_date',
        'expiry_date',
        'file_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
