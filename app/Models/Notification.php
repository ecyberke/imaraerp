<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    public const TYPES = [
        'reorder_alert', 'approval_pending', 'qc_failure', 'milestone_due',
        'retention_release_due', 'casual_conversion_due', 'dlp_ready_to_close',
        'bom_variance_exceeded', 'hire_invoice_mismatch',
    ];

    public const CHANNELS = ['in_app', 'email', 'sms'];

    public const DELIVERY_MODES = ['immediate', 'batched_daily', 'batched_weekly'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'entity_type',
        'entity_id',
        'message',
        'channel',
        'delivery_mode',
        'sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
