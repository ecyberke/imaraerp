<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant third-party integration credentials (Phase 3). `credentials`
 * is encrypted at rest - decrypt only server-side when actually calling
 * out to the provider (MpesaService etc.); never return a decrypted
 * secret field in an API response, see IntegrationCredentialController.
 */
class IntegrationCredential extends Model
{
    public const PROVIDERS = ['mpesa', 'etims'];

    /** field => [label, secret]. `secret` fields are masked on read and only overwritten when a real new value is submitted. */
    public const FIELD_SCHEMA = [
        'mpesa' => [
            'environment' => ['label' => 'Environment', 'secret' => false],
            'shortcode' => ['label' => 'Business Shortcode (Paybill/Till)', 'secret' => false],
            'consumer_key' => ['label' => 'Consumer Key', 'secret' => true],
            'consumer_secret' => ['label' => 'Consumer Secret', 'secret' => true],
            'passkey' => ['label' => 'Passkey', 'secret' => true],
        ],
        // eTIMS field schema lands with the etims-integration branch, once
        // that work actually starts, rather than being guessed at here.
    ];

    protected $fillable = [
        'tenant_id',
        'provider',
        'credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
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
