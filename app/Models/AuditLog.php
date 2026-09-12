<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (architecture §1.1/§3.10): no updated_at, never edited or
 * deleted once written. Access needs its own Policy, not blanket
 * admin-by-convention (§1.1) - see AuditLogPolicy.
 */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
