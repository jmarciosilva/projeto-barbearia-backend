<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_read_catalog_but_not_staff_routes(): void
    {
        $ownerToken = $this->ownerToken('Salao Catalogo Cliente', 'owner-catalogo@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated();

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated();

        $customerToken = $this->customerToken($ownerToken, 'carlos-catalogo@example.com');

        $this->actingWithToken($customerToken)->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingWithToken($customerToken)->getJson('/api/professionals')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingWithToken($customerToken)->getJson('/api/clients')->assertForbidden();
        $this->actingWithToken($customerToken)->getJson('/api/client-subscriptions')->assertForbidden();
    }

    public function test_customer_only_sees_active_services_and_professionals(): void
    {
        $ownerToken = $this->ownerToken('Salao Catalogo Ativo', 'owner-ativo@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated();

        $inactiveService = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Servico desativado',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->patchJson("/api/services/{$inactiveService}", [
            'is_active' => false,
        ])->assertOk();

        $customerToken = $this->customerToken($ownerToken, 'carlos-ativo@example.com');

        $services = $this->actingWithToken($customerToken)->getJson('/api/services')->assertOk();
        $services->assertJsonCount(1);
        $this->assertSame('Corte masculino', $services->json('0.name'));

        // Proprietario continua vendo o catalogo completo, ativo ou nao.
        $this->actingWithToken($ownerToken)->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_me_client_returns_own_data_and_is_restricted_to_customer(): void
    {
        $ownerToken = $this->ownerToken('Salao Perfil Cliente', 'owner-perfil-cliente@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880010',
            'email' => 'carlos-perfil@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $customerToken = $this->postJson('/api/auth/login', [
            'email' => 'carlos-perfil@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $client = $this->actingWithToken($customerToken)->getJson('/api/me/client')
            ->assertOk()
            ->assertJsonPath('name', 'Carlos Mendes');

        $clientId = $client->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $clientId,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
        ])->assertCreated();

        $this->actingWithToken($customerToken)->getJson('/api/me/client')
            ->assertOk()
            ->assertJsonPath('subscriptions.0.plan.name', 'Bronze')
            ->assertJsonPath('subscriptions.0.plan.services.0.name', 'Corte masculino');

        $this->actingWithToken($ownerToken)->getJson('/api/me/client')->assertForbidden();

        $professionalToken = $this->professionalToken($ownerToken, 'ana-perfil-cliente@example.com');
        $this->actingWithToken($professionalToken)->getJson('/api/me/client')->assertForbidden();
    }

    public function test_me_professional_returns_own_data_and_update_ignores_commission(): void
    {
        $ownerToken = $this->ownerToken('Salao Perfil Profissional', 'owner-perfil-prof@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-perfil-prof@example.com',
            'password' => 'senhaforte1',
            'specialty' => 'Cortes',
            'commission_percentage' => 40,
        ])->assertCreated();

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-perfil-prof@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $this->actingWithToken($professionalToken)->getJson('/api/me/professional')
            ->assertOk()
            ->assertJsonPath('specialty', 'Cortes')
            ->assertJsonPath('commission_percentage', 40);

        // Tenta mudar especialidade e comissao; so a especialidade deve ser aceita.
        $updated = $this->actingWithToken($professionalToken)->patchJson('/api/me/professional', [
            'specialty' => 'Cortes e barba',
            'commission_percentage' => 90,
        ])->assertOk();

        $updated->assertJsonPath('specialty', 'Cortes e barba');
        $updated->assertJsonPath('commission_percentage', 40);

        $this->actingWithToken($ownerToken)->getJson('/api/me/professional')->assertForbidden();

        $customerToken = $this->customerToken($ownerToken, 'carlos-perfil-prof@example.com');
        $this->actingWithToken($customerToken)->getJson('/api/me/professional')->assertForbidden();
        $this->actingWithToken($customerToken)->patchJson('/api/me/professional', [
            'specialty' => 'Hackeando',
        ])->assertForbidden();
    }

    public function test_professional_appointments_index_is_scoped_to_own_agenda(): void
    {
        $ownerToken = $this->ownerToken('Salao Agenda Escopada', 'owner-agenda@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880020',
        ])->assertCreated()->json('id');

        $professionalAId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-agenda@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $professionalBId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
            'email' => 'rafael-agenda@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professionalAId,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professionalBId,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(11, 0),
        ])->assertCreated();

        $professionalAToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-agenda@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $agendaA = $this->actingWithToken($professionalAToken)->getJson('/api/appointments')->assertOk();
        $agendaA->assertJsonCount(1);
        $agendaA->assertJsonPath('0.professional_id', $professionalAId);

        $professionalBToken = $this->postJson('/api/auth/login', [
            'email' => 'rafael-agenda@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $agendaB = $this->actingWithToken($professionalBToken)->getJson('/api/appointments')->assertOk();
        $agendaB->assertJsonCount(1);
        $agendaB->assertJsonPath('0.professional_id', $professionalBId);

        // Proprietario continua vendo a agenda inteira do estabelecimento.
        $this->actingWithToken($ownerToken)->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(2);
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

    private function customerToken(string $ownerToken, string $email): string
    {
        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Teste',
            'phone' => '119'.random_int(10000000, 99999999),
            'email' => $email,
            'password' => 'senhaforte1',
        ])->assertCreated();

        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'senhaforte1',
        ])->json('token');
    }

    private function professionalToken(string $ownerToken, string $email): string
    {
        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Profissional Teste',
            'email' => $email,
            'password' => 'senhaforte1',
        ])->assertCreated();

        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'senhaforte1',
        ])->json('token');
    }

    /**
     * Autentica as proximas requisicoes com o token informado.
     *
     * O guard do Sanctum cacheia o usuario resolvido durante o teste, entao
     * `forgetGuards()` e necessario sempre que o token muda no meio do mesmo
     * metodo de teste — sem isso, a troca de usuario nao tem efeito.
     */
    private function actingWithToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
