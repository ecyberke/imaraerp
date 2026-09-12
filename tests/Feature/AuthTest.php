<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * project-init exit criterion: a logged-in session round-trips through
 * Sanctum (pure bearer-token mode) against a real database. No ERP/tenant
 * entities are exercised here — that scoping is added in platform-foundation.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_login_returns_access_token(): void
    {
        $user = User::create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'charlie@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('token', fn ($token) => is_string($token) && $token !== '');

        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('user_id', $user->id);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'charlie@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }
}
