<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroBookingRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_rejected_when_professional_does_not_perform_service(): void
    {
        $ownerToken = $this->ownerToken('Salao Restricao Servico', 'owner-restricao-servico@example.com');

        $serviceA = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $serviceB = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Sobrancelha',
            'duration_minutes' => 20,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'service_ids' => [$serviceA],
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880001',
        ])->assertCreated()->json('id');

        // Servico fora da lista do profissional e recusado.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $serviceB,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertStatus(422);

        // Servico da lista do profissional e aceito.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $serviceA,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();
    }

    public function test_booking_allowed_for_any_service_when_professional_has_no_service_list(): void
    {
        $ownerToken = $this->ownerToken('Salao Sem Restricao Servico', 'owner-sem-restricao-servico@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880002',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();
    }

    public function test_booking_with_subscription_rejected_when_professional_not_allowed_by_plan(): void
    {
        $ownerToken = $this->ownerToken('Salao Restricao Plano', 'owner-restricao-plano@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $allowedProfessional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $otherProfessional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
        ])->assertCreated()->json('id');

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
            'professional_ids' => [$allowedProfessional],
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880003',
        ])->assertCreated()->json('id');

        $subscription = $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        // Profissional fora da lista do plano e recusado.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $otherProfessional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertStatus(422);

        // Profissional da lista do plano e aceito.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $allowedProfessional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();
    }

    public function test_booking_with_subscription_allowed_for_any_professional_when_plan_has_no_professional_list(): void
    {
        $ownerToken = $this->ownerToken('Salao Sem Restricao Plano', 'owner-sem-restricao-plano@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880004',
        ])->assertCreated()->json('id');

        $subscription = $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();
    }

    public function test_professional_update_syncs_service_ids_without_wiping_on_partial_update(): void
    {
        $ownerToken = $this->ownerToken('Salao Update Profissional', 'owner-update-profissional@example.com');

        $serviceA = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $serviceB = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Barba completa',
            'duration_minutes' => 20,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        // Define a lista de servicos pela primeira vez via update.
        $updated = $this->actingWithToken($ownerToken)->patchJson("/api/professionals/{$professional}", [
            'service_ids' => [$serviceA, $serviceB],
        ])->assertOk();
        $updated->assertJsonCount(2, 'services');

        // Omitir a chave em um update parcial nao apaga a lista.
        $this->actingWithToken($ownerToken)->patchJson("/api/professionals/{$professional}", [
            'specialty' => 'Cortes e barba',
        ])->assertOk()->assertJsonCount(2, 'services');

        // Enviar [] explicitamente limpa a lista.
        $this->actingWithToken($ownerToken)->patchJson("/api/professionals/{$professional}", [
            'service_ids' => [],
        ])->assertOk()->assertJsonCount(0, 'services');
    }

    public function test_customer_lists_only_own_appointments(): void
    {
        $ownerToken = $this->ownerToken('Salao Agenda Cliente', 'owner-agenda-cliente@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $customerToken = $this->customerToken($ownerToken, 'carlos-agenda@example.com');
        $outroClienteToken = $this->customerToken($ownerToken, 'joao-agenda@example.com');

        $this->actingWithToken($customerToken)->postJson('/api/appointments', [
            'client_id' => 999,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();

        $this->actingWithToken($outroClienteToken)->postJson('/api/appointments', [
            'client_id' => 999,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(14, 0),
        ])->assertCreated();

        $ownAppointments = $this->actingWithToken($customerToken)->getJson('/api/appointments')->assertOk();
        $ownAppointments->assertJsonCount(1);

        $ownerAppointments = $this->actingWithToken($ownerToken)->getJson('/api/appointments')->assertOk();
        $ownerAppointments->assertJsonCount(2);
    }

    public function test_customer_lists_only_active_subscription_plans(): void
    {
        $ownerToken = $this->ownerToken('Salao Planos Cliente', 'owner-planos-cliente@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
        ])->assertCreated();

        $inactivePlan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Descontinuado',
            'price_cents' => 5000,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->patchJson("/api/subscription-plans/{$inactivePlan}", [
            'is_active' => false,
        ])->assertOk();

        $customerToken = $this->customerToken($ownerToken, 'carlos-planos@example.com');

        $plans = $this->actingWithToken($customerToken)->getJson('/api/subscription-plans')->assertOk();
        $plans->assertJsonCount(1);
        $this->assertSame('Bronze', $plans->json('0.name'));

        $this->actingWithToken($ownerToken)->getJson('/api/subscription-plans')
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
