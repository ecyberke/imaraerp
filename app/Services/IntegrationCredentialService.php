<?php

namespace App\Services;

use App\Models\IntegrationCredential;
use App\Models\Tenant;

/**
 * Decrypted credentials never leave this layer outward to an HTTP
 * response - IntegrationCredentialController only ever returns the
 * masked shape built here, and only a provider service (MpesaService,
 * eventually EtimsService) calls decrypted() to actually place an
 * outbound call.
 */
class IntegrationCredentialService
{
    /** @return array<string, string>|null null if the tenant hasn't configured this provider yet (or disabled it). */
    public function decrypted(Tenant $tenant, string $provider): ?array
    {
        $record = IntegrationCredential::where('tenant_id', $tenant->id)
            ->where('provider', $provider)->where('is_active', true)->first();

        return $record?->credentials;
    }

    /** Masked view for the settings screen - secret fields never carry their real value, only whether one is set. */
    public function masked(Tenant $tenant, string $provider): array
    {
        $schema = IntegrationCredential::FIELD_SCHEMA[$provider] ?? [];
        $record = IntegrationCredential::where('tenant_id', $tenant->id)->where('provider', $provider)->first();
        $stored = $record?->credentials ?? [];

        $fields = [];
        foreach ($schema as $key => $meta) {
            $fields[$key] = [
                'label' => $meta['label'],
                'secret' => $meta['secret'],
                'value' => $meta['secret']
                    ? (isset($stored[$key]) && $stored[$key] !== '' ? str_repeat('•', 8) : null)
                    : ($stored[$key] ?? null),
            ];
        }

        return [
            'provider' => $provider,
            'is_active' => $record?->is_active ?? false,
            'fields' => $fields,
        ];
    }

    /**
     * Merges incoming field values onto whatever's already stored - a
     * masked secret value (or a blank one) submitted back unchanged means
     * "leave this field alone", never "clear it". Only a genuinely new,
     * non-masked value overwrites a secret field.
     */
    public function set(Tenant $tenant, string $provider, array $fields, bool $isActive): IntegrationCredential
    {
        $schema = IntegrationCredential::FIELD_SCHEMA[$provider] ?? [];
        $record = IntegrationCredential::where('tenant_id', $tenant->id)->where('provider', $provider)->first();
        $existing = $record?->credentials ?? [];

        $merged = $existing;
        foreach ($schema as $key => $meta) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }
            $incoming = $fields[$key];
            $isMaskedOrBlank = $incoming === null || $incoming === '' || ($meta['secret'] && str_starts_with((string) $incoming, '•'));
            if ($meta['secret'] && $isMaskedOrBlank) {
                continue;
            }
            $merged[$key] = $incoming;
        }

        return IntegrationCredential::updateOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => $provider],
            ['credentials' => $merged, 'is_active' => $isActive],
        );
    }
}
