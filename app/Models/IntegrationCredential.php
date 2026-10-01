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
        // KRA eTIMS OSCU. tin/bhfId/dvcSrlNo come from the taxpayer's own
        // eTIMS Sandbox/Production registration (KRA assigns these per
        // taxpayer - never guessed or defaulted). cmcKey is normally
        // filled in automatically by EtimsService::initializeDevice()
        // once the device successfully initializes against KRA, but stays
        // a plain editable field here too - KRA's documented response
        // shape for that call isn't officially published, so a manual
        // override is the safety net if auto-extraction ever misses it.
        // apigee_app_id is documented as required for the separate
        // GavaConnect *automated testing* harness, not confirmed as
        // required for ordinary Simulation Sandbox/Production calls -
        // left optional rather than assumed mandatory.
        'etims' => [
            'environment' => ['label' => 'Environment', 'secret' => false],
            'tin' => ['label' => 'KRA PIN (TIN)', 'secret' => false],
            'bhf_id' => ['label' => 'Branch ID', 'secret' => false],
            'dvc_srl_no' => ['label' => 'Device Serial Number', 'secret' => false],
            'cmc_key' => ['label' => 'Communication Key (cmcKey)', 'secret' => true],
            'apigee_app_id' => ['label' => 'Apigee App ID (optional)', 'secret' => true],
        ],
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
