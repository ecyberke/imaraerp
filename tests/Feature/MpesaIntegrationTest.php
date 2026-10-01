<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\MpesaStkRequest;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IntegrationCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * mpesa-integration branch (Phase 3): STK Push collection against a real
 * Invoice, credentials entered per-tenant via Settings (never hardcoded),
 * and the async Safaricom callback settling the resulting Payment through
 * the same PaymentService::receive() path a manually-recorded M-Pesa
 * payment uses.
 */
class MpesaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Currency $kes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme Builders', 'status' => 'active', 'plan_tier' => 'starter']);
        $this->kes = Currency::where('tenant_id', $this->tenant->id)->where('is_base', true)->first();
    }

    private function headersFor(string $email, string $role): array
    {
        return ['Authorization' => 'Bearer '.$this->userFor($email, $role)->createToken('test')->plainTextToken];
    }

    private function userFor(string $email, string $role): User
    {
        $roleRow = Role::where('tenant_id', $this->tenant->id)->where('name', $role)->first();

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'role_id' => $roleRow->id, 'password' => bcrypt('password123'),
            'mfa_enabled' => in_array($role, ['finance', 'admin'], true),
        ]);
    }

    private function configureMpesaCredentials(array $headers): void
    {
        $this->withHeaders($headers)->putJson('/api/integration-credentials/mpesa', [
            'is_active' => true,
            'fields' => [
                'environment' => 'sandbox',
                'shortcode' => '174379',
                'consumer_key' => 'test-consumer-key',
                'consumer_secret' => 'test-consumer-secret',
                'passkey' => 'test-passkey',
            ],
        ])->assertOk();

        // Sanctum's guard caches the first user it resolves per test
        // (RequestGuard::$user, never cleared by setRequest()) - every
        // caller of this helper immediately follows with requests as a
        // *different* user, so force a fresh resolution now rather than
        // silently authenticating all of those as this admin too.
        $this->app['auth']->forgetGuards();
    }

    private function makeInvoice(array $headers, Party $party, float $unitPrice = 1000): array
    {
        $salesOrder = SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'supply_path' => 'direct_sale',
            'feasibility_status' => 'passed', 'invoice_policy' => 'on_order', 'status' => 'approved',
            'currency_id' => $this->kes->id, 'exchange_rate' => 1,
        ]);

        $invoice = $this->withHeaders($headers)->postJson('/api/invoices', [
            'sales_order_id' => $salesOrder->id, 'payment_terms' => 'cash',
            'lines' => [['description' => 'Cement', 'quantity' => 1, 'unit_price' => $unitPrice]],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/invoices/{$invoice->json('id')}/raise")->assertOk();

        return $invoice->json();
    }

    public function test_non_admin_cannot_configure_integration_credentials(): void
    {
        $headers = $this->headersFor('proc@example.com', 'procurement');

        $this->withHeaders($headers)->putJson('/api/integration-credentials/mpesa', [
            'is_active' => true, 'fields' => ['shortcode' => '174379'],
        ])->assertStatus(403);
    }

    public function test_admin_can_configure_mpesa_credentials_and_secrets_are_masked_on_read(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureMpesaCredentials($headers);

        $listed = $this->withHeaders($headers)->getJson('/api/integration-credentials')->assertOk()->json();
        $mpesa = collect($listed)->firstWhere('provider', 'mpesa');

        $this->assertTrue($mpesa['is_active']);
        $this->assertSame('174379', $mpesa['fields']['shortcode']['value']);
        $this->assertNotSame('test-consumer-secret', $mpesa['fields']['consumer_secret']['value']);
        $this->assertStringStartsNotWith('test-', (string) $mpesa['fields']['consumer_secret']['value']);
    }

    public function test_resubmitting_a_masked_secret_does_not_overwrite_the_stored_value(): void
    {
        $headers = $this->headersFor('admin@example.com', 'admin');
        $this->configureMpesaCredentials($headers);

        $masked = $this->withHeaders($headers)->getJson('/api/integration-credentials')->json();
        $maskedSecret = collect($masked)->firstWhere('provider', 'mpesa')['fields']['consumer_secret']['value'];

        $this->withHeaders($headers)->putJson('/api/integration-credentials/mpesa', [
            'is_active' => true,
            'fields' => [
                'environment' => 'sandbox', 'shortcode' => '174379',
                'consumer_key' => 'test-consumer-key', 'consumer_secret' => $maskedSecret, 'passkey' => 'test-passkey',
            ],
        ])->assertOk();

        $stored = app(IntegrationCredentialService::class)->decrypted($this->tenant, 'mpesa');
        $this->assertSame('test-consumer-secret', $stored['consumer_secret']);
    }

    public function test_stk_push_cannot_be_initiated_without_configured_credentials(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);

        $this->withHeaders($headers)->postJson('/api/mpesa/stk-requests', [
            'party_id' => $party->id, 'amount' => 1000, 'phone_number' => '0712345678',
        ])->assertStatus(500);
    }

    public function test_stk_push_initiates_and_a_successful_callback_settles_the_invoice(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $this->configureMpesaCredentials($this->headersFor('admin@example.com', 'admin'));

        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);
        $invoice = $this->makeInvoice($headers, $party, 1000);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-1', 'CheckoutRequestID' => 'ws_CO_1',
                'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);

        $created = $this->withHeaders($headers)->postJson('/api/mpesa/stk-requests', [
            'party_id' => $party->id, 'invoice_id' => $invoice['id'], 'amount' => $invoice['net_payable'], 'phone_number' => '0712345678',
        ])->assertCreated();

        $this->assertSame('pending', $created->json('status'));
        $this->assertSame('ws_CO_1', $created->json('checkout_request_id'));

        $this->postJson('/api/webhooks/mpesa/callback', [
            'Body' => ['stkCallback' => [
                'MerchantRequestID' => 'merchant-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 1000], ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                    ['Name' => 'TransactionDate', 'Value' => 20261001103000], ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ]],
            ]],
        ])->assertOk();

        $stkRequest = MpesaStkRequest::where('checkout_request_id', 'ws_CO_1')->first();
        $this->assertSame('completed', $stkRequest->status);
        $this->assertSame('NLJ7RT61SV', $stkRequest->mpesa_receipt_number);
        $this->assertNotNull($stkRequest->payment_id);

        $payment = Payment::find($stkRequest->payment_id);
        $this->assertSame('mpesa', $payment->method);
        $this->assertSame('NLJ7RT61SV', $payment->mpesa_reference);

        $raised = $this->withHeaders($headers)->getJson("/api/invoices/{$invoice['id']}")->json();
        $this->assertSame('paid', $raised['status']);
    }

    public function test_a_cancelled_stk_push_is_recorded_without_creating_a_payment(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $this->configureMpesaCredentials($this->headersFor('admin@example.com', 'admin'));

        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-2', 'CheckoutRequestID' => 'ws_CO_2', 'ResponseCode' => '0',
            ]),
        ]);

        $this->withHeaders($headers)->postJson('/api/mpesa/stk-requests', [
            'party_id' => $party->id, 'amount' => 500, 'phone_number' => '0712345678',
        ])->assertCreated();

        $this->postJson('/api/webhooks/mpesa/callback', [
            'Body' => ['stkCallback' => [
                'MerchantRequestID' => 'merchant-2', 'CheckoutRequestID' => 'ws_CO_2',
                'ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user',
            ]],
        ])->assertOk();

        $stkRequest = MpesaStkRequest::where('checkout_request_id', 'ws_CO_2')->first();
        $this->assertSame('cancelled', $stkRequest->status);
        $this->assertNull($stkRequest->payment_id);
        $this->assertSame(0, Payment::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_duplicate_callback_is_idempotent_and_never_creates_a_second_payment(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $this->configureMpesaCredentials($this->headersFor('admin@example.com', 'admin'));

        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-3', 'CheckoutRequestID' => 'ws_CO_3', 'ResponseCode' => '0',
            ]),
        ]);

        $this->withHeaders($headers)->postJson('/api/mpesa/stk-requests', [
            'party_id' => $party->id, 'amount' => 500, 'phone_number' => '0712345678',
        ])->assertCreated();

        $callbackBody = [
            'Body' => ['stkCallback' => [
                'MerchantRequestID' => 'merchant-3', 'CheckoutRequestID' => 'ws_CO_3', 'ResultCode' => 0, 'ResultDesc' => 'Success',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500], ['Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123XYZ'],
                    ['Name' => 'TransactionDate', 'Value' => 20261001103000], ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/mpesa/callback', $callbackBody)->assertOk();
        $this->postJson('/api/webhooks/mpesa/callback', $callbackBody)->assertOk();

        $this->assertSame(1, Payment::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_mpesa_stk_request_is_tenant_isolated(): void
    {
        $headers = $this->headersFor('finance@example.com', 'finance');
        $this->configureMpesaCredentials($this->headersFor('admin@example.com', 'admin'));

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active', 'plan_tier' => 'starter']);
        // withoutGlobalScopes(): TenantScope resolves against the *currently
        // authenticated* user (the admin from configureMpesaCredentials()'s
        // request just above), which would otherwise silently AND an extra
        // tenant_id = $this->tenant->id onto this deliberately
        // cross-tenant lookup and return null.
        $otherRole = Role::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->where('name', 'finance')->first();
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Other Finance', 'email' => 'other@example.com',
            'role_id' => $otherRole->id, 'password' => bcrypt('password123'), 'mfa_enabled' => true,
        ]);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherUser->createToken('test')->plainTextToken];

        $party = Party::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client Co', 'type' => 'customer',
            'tax_residency_status' => 'resident_certified',
        ]);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'merchant-4', 'CheckoutRequestID' => 'ws_CO_4', 'ResponseCode' => '0',
            ]),
        ]);

        $stkRequest = $this->withHeaders($headers)->postJson('/api/mpesa/stk-requests', [
            'party_id' => $party->id, 'amount' => 500, 'phone_number' => '0712345678',
        ])->assertCreated();

        // Sanctum's guard caches whichever user it first resolved within a
        // test (RequestGuard::$user is never reset by setRequest()) - force
        // it to re-resolve against $otherHeaders's token rather than
        // silently reusing this tenant's already-authenticated user.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($otherHeaders)->getJson("/api/mpesa/stk-requests/{$stkRequest->json('id')}")->assertStatus(404);
    }
}
