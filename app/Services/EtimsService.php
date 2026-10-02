<?php

namespace App\Services;

use App\Models\EtimsItemClassification;
use App\Models\EtimsSubmission;
use App\Models\Tenant;
use App\Models\TenantFeatureFlag;
use Illuminate\Support\Facades\Http;

/**
 * KRA eTIMS OSCU (Phase 3, §8/Two-Year Outlook). Unlike MpesaService, this
 * is deliberately a thin first slice, not a full integration - see the
 * mpesa-integration branch's own PR discussion for why: KRA's official
 * OSCU specification/Postman material documents *request* shapes in
 * detail but publishes no example *response* bodies anywhere this app has
 * access to, and the one Postman collection available (the GavaConnect
 * *Automated Testing* harness) targets a different, time-boxed
 * certification environment with its own path suffixes - not the
 * ongoing Simulation Sandbox/Production paths this service actually
 * calls (KRA's own onboarding guide, §7, documents both sets side by
 * side and they differ, e.g. initialization is /selectInitOsdcInfo here
 * vs /initialize there).
 *
 * Every response-field name this service reads (cmcKey, resultCd,
 * itemClsCd/itemClsNm) is a best-effort inference from third-party SDK
 * READMEs, not an official KRA example - EVERY call stores its full raw
 * response on an EtimsSubmission row specifically so a wrong guess here
 * is recoverable (an admin can read the raw payload and fix the
 * extraction logic, or enter a value like cmcKey manually on the
 * Integrations settings screen) rather than silently lost. Do not trust
 * this service's parsed fields over its own raw_response until it's been
 * verified against a real KRA sandbox response at least once.
 *
 * Item registration, Sales/Purchase/Stock Management, and Branch/Customer
 * Information Management are NOT built here - KRA's own onboarding guide
 * sequences Item registration *before* Sales submission (a sales
 * transaction references item codes that must already exist in eTIMS),
 * and item registration itself needs a real itemClsCd chosen per item,
 * which is what syncItemClassifications() exists to make possible - that
 * mapping work is the next slice, done once this one is verified against
 * a real sandbox response.
 */
class EtimsService
{
    public function __construct(
        private IntegrationCredentialService $credentials,
        private FeatureFlagService $flags,
    ) {}

    private function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://etims.kra.go.ke'
            : 'https://etims-api-sbx.kra.go.ke';
    }

    private function credentialsFor(Tenant $tenant, bool $requireCmcKey): array
    {
        $creds = $this->credentials->decrypted($tenant, 'etims');

        if (! $creds || empty($creds['tin']) || empty($creds['bhf_id']) || empty($creds['dvc_srl_no'])) {
            throw new \DomainException('eTIMS is not configured for this tenant. Set the KRA PIN, Branch ID and Device Serial Number under Settings > Integrations first.');
        }

        if ($requireCmcKey && empty($creds['cmc_key'])) {
            throw new \DomainException('This device has not been initialized with eTIMS yet (no Communication Key on file). Use "Initialize Device" under Settings > Integrations first.');
        }

        return $creds;
    }

    private function headers(array $creds, bool $includeCmcKey): array
    {
        $headers = ['tin' => $creds['tin'], 'bhfId' => $creds['bhf_id']];

        if ($includeCmcKey) {
            $headers['cmcKey'] = $creds['cmc_key'];
        }
        if (! empty($creds['apigee_app_id'])) {
            $headers['apigee_app_id'] = $creds['apigee_app_id'];
        }

        return $headers;
    }

    /** '000' is inferred as the success code from the most complete public resultCd reference found (see this class's own docblock) - treated as the success signal, everything else as failure. */
    private function isSuccessResult(?string $resultCd): bool
    {
        return $resultCd === '000';
    }

    /**
     * One-time (or re-run-after-a-KRA-reinitialization) step: establishes
     * the communication key (cmcKey) every other OSCU call needs. Must
     * succeed before syncItemClassifications() or anything built on top
     * of this service later can work.
     */
    public function initializeDevice(Tenant $tenant): EtimsSubmission
    {
        $this->flags->ensureEnabled($tenant, TenantFeatureFlag::ETIMS);
        $creds = $this->credentialsFor($tenant, requireCmcKey: false);

        $payload = ['tin' => $creds['tin'], 'bhfId' => $creds['bhf_id'], 'dvcSrlNo' => $creds['dvc_srl_no']];

        $submission = EtimsSubmission::create([
            'tenant_id' => $tenant->id, 'type' => 'device_init', 'status' => 'pending', 'request_payload' => $payload,
        ]);

        $response = Http::withHeaders($this->headers($creds, includeCmcKey: false))
            ->post($this->baseUrl($creds['environment']).'/selectInitOsdcInfo', $payload);

        $body = $response->json() ?? [];
        $resultCd = $body['resultCd'] ?? null;
        $cmcKey = $body['cmcKey'] ?? $body['data']['cmcKey'] ?? null;

        $submission->response_payload = $body;
        $submission->result_code = $resultCd;
        $submission->result_desc = $body['resultMsg'] ?? null;

        if ($response->successful() && $this->isSuccessResult($resultCd) && $cmcKey) {
            $this->credentials->set($tenant, 'etims', ['cmc_key' => $cmcKey], true);
            $submission->status = 'success';
        } else {
            $submission->status = 'failed';
            $submission->result_desc ??= $cmcKey
                ? null
                : 'eTIMS did not return a recognizable cmcKey in the response - check response_payload on this submission and, if you can identify it there, enter it manually under Settings > Integrations.';
        }

        $submission->save();

        return $submission;
    }

    /**
     * Fetches KRA's own item classification code list (Basic Data
     * Management) and caches it locally - every eTIMS item registration
     * needs a real itemClsCd, and this is what will let that screen (not
     * built yet) offer a real picker instead of a free-text code field.
     */
    public function syncItemClassifications(Tenant $tenant): EtimsSubmission
    {
        $this->flags->ensureEnabled($tenant, TenantFeatureFlag::ETIMS);
        $creds = $this->credentialsFor($tenant, requireCmcKey: true);

        $payload = ['tin' => $creds['tin'], 'bhfId' => $creds['bhf_id'], 'lastReqDt' => '20200101000000'];

        $submission = EtimsSubmission::create([
            'tenant_id' => $tenant->id, 'type' => 'item_classification_sync', 'status' => 'pending', 'request_payload' => $payload,
        ]);

        $response = Http::withHeaders($this->headers($creds, includeCmcKey: true))
            ->post($this->baseUrl($creds['environment']).'/selectItemClsList', $payload);

        $body = $response->json() ?? [];
        $resultCd = $body['resultCd'] ?? null;
        $list = $body['data']['itemClsList'] ?? $body['itemClsList'] ?? [];

        $synced = 0;
        foreach ($list as $item) {
            $code = $item['itemClsCd'] ?? null;
            if (! $code) {
                continue;
            }
            EtimsItemClassification::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $item['itemClsNm'] ?? null],
            );
            $synced++;
        }

        $submission->response_payload = $body;
        $submission->result_code = $resultCd;
        $submission->status = $synced > 0 ? 'success' : 'failed';
        $submission->result_desc = $synced > 0
            ? "Synced {$synced} item classification code(s)."
            : ($body['resultMsg'] ?? 'No item classification codes were found in the response - check response_payload on this submission.');
        $submission->save();

        return $submission;
    }
}
