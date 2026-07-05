<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /me/credentials: qualquer papel autenticado troca o proprio e-mail
 * e/ou senha de login, exigindo a senha atual por seguranca.
 */
class AccountCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_change_own_email(): void
    {
        $token = $this->ownerToken('Salao Um', 'dono1@example.com');

        $this->actingWithToken($token)->patchJson('/api/me/credentials', [
            'current_password' => 'password123',
            'email' => 'novo-email@example.com',
        ])->assertOk()->assertJsonPath('email', 'novo-email@example.com');

        $this->actingWithToken($token)->postJson('/api/auth/login', [
            'email' => 'novo-email@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_owner_can_change_own_password(): void
    {
        $token = $this->ownerToken('Salao Dois', 'dono2@example.com');

        $this->actingWithToken($token)->patchJson('/api/me/credentials', [
            'current_password' => 'password123',
            'password' => 'novaSenhaForte1',
            'password_confirmation' => 'novaSenhaForte1',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'dono2@example.com',
            'password' => 'novaSenhaForte1',
        ])->assertOk();
    }

    public function test_change_credentials_rejects_wrong_current_password(): void
    {
        $token = $this->ownerToken('Salao Tres', 'dono3@example.com');

        $this->actingWithToken($token)->patchJson('/api/me/credentials', [
            'current_password' => 'senhaErrada',
            'email' => 'outro@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_change_credentials_requires_email_or_password(): void
    {
        $token = $this->ownerToken('Salao Quatro', 'dono4@example.com');

        $this->actingWithToken($token)->patchJson('/api/me/credentials', [
            'current_password' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_change_credentials_rejects_email_already_in_use(): void
    {
        $this->ownerToken('Salao Cinco', 'dono5@example.com');
        $token = $this->ownerToken('Salao Seis', 'dono6@example.com');

        $this->actingWithToken($token)->patchJson('/api/me/credentials', [
            'current_password' => 'password123',
            'email' => 'dono5@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_change_credentials_is_allowed_even_after_trial_expires(): void
    {
        $token = $this->ownerToken('Salao Sete', 'dono7@example.com');

        \App\Models\SaasSubscription::where('tenant_id', \App\Models\Tenant::where('name', 'Salao Sete')->value('id'))
            ->update(['trial_ends_at' => now()->subDay()]);

        // Escrita comum bloqueada (402) com o trial vencido...
        $this->actingWithToken($token)->postJson('/api/services', [
            'name' => 'Corte', 'duration_minutes' => 30,
        ])->assertStatus(402);

        // ...mas trocar a propria credencial continua liberado, senao o dono
        // fica trancado sem conseguir nem corrigir a propria senha.
        $this->actingWithToken($token)->patchJson('/api/me/credentials', [
            'current_password' => 'password123',
            'email' => 'dono7-novo@example.com',
        ])->assertOk();
    }

    private function ownerToken(string $tenantName, string $email): string
    {
        return $this->postJson('/api/auth/register-owner', [
            'tenant' => [
                'name' => $tenantName,
                'business_type' => 'barbershop',
            ],
            'owner' => [
                'name' => 'Responsavel',
                'email' => $email,
                'password' => 'password123',
            ],
        ])->assertCreated()->json('token');
    }

    private function actingWithToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
