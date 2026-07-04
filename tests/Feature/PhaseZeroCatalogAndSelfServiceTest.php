<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroCatalogAndSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_created_with_service_ids_syncs_pivot(): void
    {
        $ownerToken = $this->ownerToken('Salao Pivot Servico', 'owner-pivot-servico@example.com');

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
            'service_ids' => [$serviceA, $serviceB],
        ])->assertCreated();

        $professional->assertJsonCount(2, 'services');
    }

    public function test_professional_store_rejects_service_from_another_tenant(): void
    {
        $ownerToken = $this->ownerToken('Salao Pivot Servico Alheio', 'owner-pivot-alheio@example.com');
        $outroTenantToken = $this->ownerToken('Outro Salao', 'owner-outro-tenant@example.com');

        $servicoAlheio = $this->actingWithToken($outroTenantToken)->postJson('/api/services', [
            'name' => 'Corte de outro salao',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'service_ids' => [$servicoAlheio],
        ])->assertStatus(422);
    }

    public function test_plan_created_with_professional_ids_syncs_pivot(): void
    {
        $ownerToken = $this->ownerToken('Salao Pivot Plano', 'owner-pivot-plano@example.com');

        $professionalA = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $professionalB = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
        ])->assertCreated()->json('id');

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'professional_ids' => [$professionalA, $professionalB],
        ])->assertCreated();

        $plan->assertJsonCount(2, 'professionals');

        // Omitir a chave em um update nao deve apagar a lista.
        $planId = $plan->json('id');
        $updated = $this->actingWithToken($ownerToken)->patchJson("/api/subscription-plans/{$planId}", [
            'name' => 'Bronze Plus',
        ])->assertOk();
        $updated->assertJsonCount(2, 'professionals');

        // Enviar [] explicitamente limpa a restricao.
        $cleared = $this->actingWithToken($ownerToken)->patchJson("/api/subscription-plans/{$planId}", [
            'professional_ids' => [],
        ])->assertOk();
        $cleared->assertJsonCount(0, 'professionals');
    }

    public function test_customer_can_subscribe_switch_and_cancel_own_plan(): void
    {
        $ownerToken = $this->ownerToken('Salao Assinatura Cliente', 'owner-assinatura-cliente@example.com');

        $bronze = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
        ])->assertCreated()->json('id');

        $prata = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Prata',
            'price_cents' => 14990,
        ])->assertCreated()->json('id');

        $customerToken = $this->customerToken($ownerToken, 'carlos-assinatura@example.com');

        // owner/professional nao acessam a rota self-service.
        $this->actingWithToken($ownerToken)->postJson('/api/me/client-subscriptions', [
            'subscription_plan_id' => $bronze,
        ])->assertForbidden();

        $first = $this->actingWithToken($customerToken)->postJson('/api/me/client-subscriptions', [
            'subscription_plan_id' => $bronze,
        ])->assertCreated();
        $first->assertJsonPath('status', 'active');
        $first->assertJsonPath('plan.name', 'Bronze');

        // Trocar de plano: assinatura antiga cancelada, nova ativa.
        $second = $this->actingWithToken($customerToken)->postJson('/api/me/client-subscriptions', [
            'subscription_plan_id' => $prata,
        ])->assertCreated();
        $second->assertJsonPath('status', 'active');
        $second->assertJsonPath('plan.name', 'Prata');

        $client = $this->actingWithToken($customerToken)->getJson('/api/me/client')->assertOk();
        $activeSubscriptions = collect($client->json('subscriptions'))->where('status', 'active');
        $this->assertCount(1, $activeSubscriptions);

        $this->actingWithToken($customerToken)->postJson('/api/me/client-subscriptions/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'canceled');

        // Sem assinatura ativa, cancelar de novo falha.
        $this->actingWithToken($customerToken)->postJson('/api/me/client-subscriptions/cancel')
            ->assertStatus(404);
    }

    public function test_customer_can_cancel_and_reschedule_own_appointment_but_not_reassign_or_complete(): void
    {
        $ownerToken = $this->ownerToken('Salao Cancelamento Cliente', 'owner-cancelamento@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professionalA = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $professionalB = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
        ])->assertCreated()->json('id');

        $customerToken = $this->customerToken($ownerToken, 'carlos-cancelamento@example.com');

        $appointment = $this->actingWithToken($customerToken)->postJson('/api/appointments', [
            'client_id' => 999, // ignorado pelo backend para customer
            'professional_id' => $professionalA,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();
        $appointmentId = $appointment->json('id');

        // Outro cliente nao pode mexer neste agendamento.
        $outroClienteToken = $this->customerToken($ownerToken, 'outro-cliente-cancelamento@example.com');
        $this->actingWithToken($outroClienteToken)->patchJson("/api/appointments/{$appointmentId}", [
            'status' => 'canceled',
        ])->assertForbidden();

        // Cliente dono nao pode trocar o profissional.
        $this->actingWithToken($customerToken)->patchJson("/api/appointments/{$appointmentId}", [
            'professional_id' => $professionalB,
        ])->assertForbidden();

        // Cliente dono nao pode se autoconcluir o atendimento.
        $this->actingWithToken($customerToken)->patchJson("/api/appointments/{$appointmentId}", [
            'status' => 'completed',
        ])->assertStatus(422);

        // Remarcar (mudar starts_at) e permitido.
        $novoHorario = now()->addDay()->setTime(14, 0);
        $this->actingWithToken($customerToken)->patchJson("/api/appointments/{$appointmentId}", [
            'starts_at' => $novoHorario->toIso8601String(),
        ])->assertOk()->assertJsonPath('status', 'scheduled');

        // Cancelar o proprio agendamento e permitido.
        $this->actingWithToken($customerToken)->patchJson("/api/appointments/{$appointmentId}", [
            'status' => 'canceled',
        ])->assertOk()->assertJsonPath('status', 'canceled');
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
