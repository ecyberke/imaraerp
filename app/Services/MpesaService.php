<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MpesaStkRequest;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom Daraja (M-Pesa) STK Push. Every call is made with the calling
 * Tenant's own credentials (IntegrationCredentialService) - never a
 * global/shared Daraja app, since every client has their own Safaricom
 * account and shortcode. See MpesaWebhookController for why the inbound
 * callback is unauthenticated and how it's still correlated to the right
 * tenant.
 */
class MpesaService
{
    public function __construct(
        private IntegrationCredentialService $credentials,
        private PaymentService $payments,
    ) {}

    private function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function credentialsFor(Tenant $tenant): array
    {
        $creds = $this->credentials->decrypted($tenant, 'mpesa');

        if (! $creds || empty($creds['consumer_key']) || empty($creds['consumer_secret']) || empty($creds['shortcode']) || empty($creds['passkey'])) {
            throw new \DomainException('M-Pesa is not configured for this tenant. Set it up under Settings > Integrations first.');
        }

        return $creds;
    }

    /** Cached ~50 minutes (Daraja tokens are valid ~1hr) so a burst of STK pushes doesn't re-authenticate every time. */
    private function accessToken(Tenant $tenant, array $creds): string
    {
        return Cache::remember("mpesa_token:{$tenant->id}:{$creds['environment']}", 3000, function () use ($creds) {
            $response = Http::withBasicAuth($creds['consumer_key'], $creds['consumer_secret'])
                ->get($this->baseUrl($creds['environment']).'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new \DomainException('Could not authenticate with M-Pesa. Check the configured Consumer Key/Secret.');
            }

            return $response->json('access_token');
        });
    }

    /** Safaricom requires 2547XXXXXXXX / 2541XXXXXXXX - no leading 0 or +. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0')) {
            return '254'.substr($digits, 1);
        }
        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        return '254'.$digits;
    }

    public function initiateStkPush(Tenant $tenant, Party $party, Money $amount, string $phoneNumber, ?Invoice $invoice, User $initiatedBy): MpesaStkRequest
    {
        $creds = $this->credentialsFor($tenant);
        $phone = $this->normalizePhone($phoneNumber);
        $timestamp = BusinessTime::now()->format('YmdHis');
        $password = base64_encode($creds['shortcode'].$creds['passkey'].$timestamp);

        $stkRequest = MpesaStkRequest::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'invoice_id' => $invoice?->id,
            'initiated_by' => $initiatedBy->id,
            'amount_cents' => $amount->cents(),
            'phone_number' => $phone,
            'status' => 'pending',
        ]);

        $reference = $invoice ? substr($invoice->document_number, 0, 12) : substr($party->name, 0, 12);

        $response = Http::withToken($this->accessToken($tenant, $creds))
            ->post($this->baseUrl($creds['environment']).'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $creds['shortcode'],
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) ceil((float) $amount->toMajor()),
                'PartyA' => $phone,
                'PartyB' => $creds['shortcode'],
                'PhoneNumber' => $phone,
                'CallBackURL' => route('webhooks.mpesa.callback'),
                'AccountReference' => $reference,
                'TransactionDesc' => $invoice ? "Invoice {$invoice->document_number}" : 'Payment',
            ]);

        if (! $response->successful() || $response->json('ResponseCode') !== '0') {
            $stkRequest->update([
                'status' => 'failed',
                'result_desc' => $response->json('errorMessage') ?? $response->json('ResponseDescription') ?? 'M-Pesa did not accept the STK Push request.',
            ]);

            throw new \DomainException($stkRequest->result_desc);
        }

        $stkRequest->update([
            'merchant_request_id' => $response->json('MerchantRequestID'),
            'checkout_request_id' => $response->json('CheckoutRequestID'),
        ]);

        return $stkRequest->fresh();
    }

    /**
     * Idempotent: only a still-'pending' request is ever acted on, so a
     * replayed callback (Safaricom retries on a non-200 response) for an
     * already-settled request is a safe no-op, never a second Payment.
     * No authenticated user/tenant on this request at all - the tenant is
     * derived entirely from the MpesaStkRequest the CheckoutRequestID
     * resolves to, looked up without the tenant scope for exactly that
     * reason.
     */
    public function handleCallback(array $payload): ?MpesaStkRequest
    {
        $callback = $payload['Body']['stkCallback'] ?? null;
        if (! $callback || empty($callback['CheckoutRequestID'])) {
            Log::warning('M-Pesa callback received with no recognizable stkCallback body.', ['payload' => $payload]);

            return null;
        }

        $stkRequest = MpesaStkRequest::withoutGlobalScopes()
            ->where('checkout_request_id', $callback['CheckoutRequestID'])->first();

        if (! $stkRequest) {
            Log::warning('M-Pesa callback for an unknown CheckoutRequestID.', ['checkout_request_id' => $callback['CheckoutRequestID']]);

            return null;
        }

        if ($stkRequest->status !== 'pending') {
            return $stkRequest;
        }

        $stkRequest->raw_callback = $payload;
        $resultCode = (string) $callback['ResultCode'];

        if ($resultCode !== '0') {
            $stkRequest->status = $resultCode === '1032' ? 'cancelled' : 'failed';
            $stkRequest->result_code = $resultCode;
            $stkRequest->result_desc = $callback['ResultDesc'] ?? null;
            $stkRequest->save();

            return $stkRequest;
        }

        $metadata = collect($callback['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');

        return DB::transaction(function () use ($stkRequest, $callback, $metadata) {
            $tenant = Tenant::findOrFail($stkRequest->tenant_id);
            $party = Party::withoutGlobalScopes()->findOrFail($stkRequest->party_id);
            $receiptNumber = (string) $metadata->get('MpesaReceiptNumber');

            $payment = $this->payments->receive(
                tenant: $tenant,
                party: $party,
                invoiceAllocations: $stkRequest->invoice_id
                    ? [['invoice_id' => $stkRequest->invoice_id, 'amount' => $stkRequest->amount->toMajor()]]
                    : [],
                advanceAmount: $stkRequest->invoice_id ? '0' : $stkRequest->amount->toMajor(),
                method: 'mpesa',
                mpesaReference: $receiptNumber,
            );

            $stkRequest->status = 'completed';
            $stkRequest->result_code = '0';
            $stkRequest->result_desc = $callback['ResultDesc'] ?? null;
            $stkRequest->mpesa_receipt_number = $receiptNumber;
            $stkRequest->payment_id = $payment->id;
            $stkRequest->save();

            return $stkRequest;
        });
    }
}
