<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\UserInvitation;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exit criterion's first clause, in full: "a Tenant can be provisioned
 * end-to-end (created, admin invited via UserInvitation, invitation
 * accepted)." Earlier tests only ever created a UserInvitation row
 * directly via Eloquent - this exercises the real acceptance flow an
 * invited admin actually goes through.
 */
class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_creates_a_tenant_and_a_first_admin_invitation(): void
    {
        $invitation = (new TenantProvisioningService)->provision('Acme Contractors', 'owner@acme.test');

        $this->assertInstanceOf(UserInvitation::class, $invitation);
        $this->assertDatabaseHas('tenants', ['name' => 'Acme Contractors']);
        $this->assertSame('owner@acme.test', $invitation->email);
        $this->assertSame('admin', $invitation->role->name);
        $this->assertNull($invitation->accepted_at);
    }

    public function test_invitation_can_be_accepted_and_returns_a_working_bearer_token(): void
    {
        $invitation = (new TenantProvisioningService)->provision('Acme Contractors', 'owner@acme.test');

        $response = $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'Owner Admin',
            'password' => 'a-genuinely-long-passphrase',
            'password_confirmation' => 'a-genuinely-long-passphrase',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'owner@acme.test')
            ->assertJsonPath('user.role', 'admin');

        $this->assertDatabaseHas('user_invitations', [
            'id' => $invitation->id,
        ]);
        $this->assertNotNull($invitation->refresh()->accepted_at);

        // The returned token actually works.
        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/health')
            ->assertOk();
    }

    public function test_an_already_accepted_invitation_cannot_be_accepted_again(): void
    {
        $invitation = (new TenantProvisioningService)->provision('Acme', 'owner@acme.test');

        $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'Owner',
            'password' => 'a-genuinely-long-passphrase',
            'password_confirmation' => 'a-genuinely-long-passphrase',
        ])->assertCreated();

        $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'Someone Else',
            'password' => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ])->assertStatus(422);
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'status' => 'active', 'plan_tier' => 'starter']);
        $adminRole = \App\Models\Role::where('tenant_id', $tenant->id)->where('name', 'admin')->first();

        $invitation = UserInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'owner@acme.test',
            'role_id' => $adminRole->id,
            'token' => 'expired-token',
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'Owner',
            'password' => 'a-genuinely-long-passphrase',
            'password_confirmation' => 'a-genuinely-long-passphrase',
        ])->assertStatus(422);
    }

    public function test_an_unknown_token_returns_404(): void
    {
        $this->postJson('/api/invitations/does-not-exist/accept', [
            'name' => 'Owner',
            'password' => 'a-genuinely-long-passphrase',
            'password_confirmation' => 'a-genuinely-long-passphrase',
        ])->assertNotFound();
    }
}
