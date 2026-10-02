<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class EtimsSubmission extends Model
{
    public const TYPES = ['device_init', 'item_classification_sync'];

    public const STATUSES = ['pending', 'success', 'failed'];

    protected $fillable = [
        'tenant_id',
        'type',
        'status',
        'request_payload',
        'response_payload',
        'result_code',
        'result_desc',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
