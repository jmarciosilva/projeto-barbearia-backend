<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroAvulsoAndWaitlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_without_subscription_creates_pending_avulso_payment(): void
    {
        $ownerToken = $this->ownerToken('Salao Avulso Pagamento', 'owner-avulso-pagamento@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880001',
        ])->assertCreated()->json('id');

        $appointment = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();

        $appointment->assertJsonPath('payment.amount_cents', 6000);
        $appointment->assertJsonPath('payment.status', 'pending');
        $appointmentId = $appointment->json('id');

        $payments = $this->actingWithToken($ownerToken)->getJson('/api/payments')->assertOk();
        $payments->assertJsonCount(1);
        $payments->assertJsonPath('0.appointment_id', $appointmentId);
        $payments->assertJsonPath('0.client_id', $client);
        $payments->assertJsonPath('0.client_subscription_id', null);
    }

    public function test_appointment_with_subscription_does_not_create_avulso_payment(): void
    {
        $ownerToken = $this->ownerToken('Salao Assinante Sem Avulso', 'owner-assinante-sem-avulso@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880002',
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
        ])->assertCreated()->assertJsonPath('payment', null);

        $this->actingWithToken($ownerToken)->getJson('/api/payments')->assertOk()->assertJsonCount(0);
    }

    public function test_owner_can_mark_avulso_payment_as_paid(): void
    {
        $ownerToken = $this->ownerToken('Salao Confirma Avulso', 'owner-confirma-avulso@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880003',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();

        $paymentId = $this->actingWithToken($ownerToken)->getJson('/api/payments')->json('0.id');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'pix',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('method', 'pix');
    }

    public function test_customer_joins_waitlist_and_staff_sees_it_but_other_customer_does_not(): void
    {
        $ownerToken = $this->ownerToken('Salao Fila Espera', 'owner-fila-espera@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $customerToken = $this->customerToken($ownerToken, 'carlos-fila@example.com');
        $outroClienteToken = $this->customerToken($ownerToken, 'joao-fila@example.com');

        $entry = $this->actingWithToken($customerToken)->postJson('/api/waitlist', [
            'service_id' => $service,
        ])->assertCreated();
        $entry->assertJsonPath('status', 'waiting');
        $entry->assertJsonPath('professional_id', null);

        // O proprio cliente ve a entrada.
        $this->actingWithToken($customerToken)->getJson('/api/waitlist')->assertOk()->assertJsonCount(1);

        // Outro cliente nao ve a fila de ninguem mais.
        $this->actingWithToken($outroClienteToken)->getJson('/api/waitlist')->assertOk()->assertJsonCount(0);

        // Staff ve a fila inteira do estabelecimento.
        $this->actingWithToken($ownerToken)->getJson('/api/waitlist')->assertOk()->assertJsonCount(1);
    }

    public function test_owner_assigns_waitlist_entry_creating_appointment_and_avulso_payment(): void
    {
        $ownerToken = $this->ownerToken('Salao Atribui Fila', 'owner-atribui-fila@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $customerToken = $this->customerToken($ownerToken, 'carlos-atribui@example.com');

        $entryId = $this->actingWithToken($customerToken)->postJson('/api/waitlist', [
            'service_id' => $service,
        ])->assertCreated()->json('id');

        $startsAt = now()->addHours(2);
        $assigned = $this->actingWithToken($ownerToken)->postJson("/api/waitlist/{$entryId}/assign", [
            'professional_id' => $professional,
            'starts_at' => $startsAt,
        ])->assertOk();

        $assigned->assertJsonPath('status', 'scheduled');
        $assigned->assertJsonPath('appointment.status', 'scheduled');
        $assigned->assertJsonPath('appointment.payment.status', 'pending');
        $assigned->assertJsonPath('appointment.payment.amount_cents', 6000);

        // Cliente so ve os proprios agendamentos e ja aparece o novo, criado a partir da fila.
        $this->actingWithToken($customerToken)->getJson('/api/appointments')->assertOk()->assertJsonCount(1);
    }

    public function test_assign_rejects_professional_that_does_not_perform_the_service(): void
    {
        $ownerToken = $this->ownerToken('Salao Fila Restricao Servico', 'owner-fila-restricao-servico@example.com');

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

        $customerToken = $this->customerToken($ownerToken, 'carlos-fila-restricao@example.com');

        $entryId = $this->actingWithToken($customerToken)->postJson('/api/waitlist', [
            'service_id' => $serviceB,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson("/api/waitlist/{$entryId}/assign", [
            'professional_id' => $professional,
            'starts_at' => now()->addHours(2),
        ])->assertStatus(422);
    }

    public function test_assign_rejects_time_conflict_with_existing_appointment(): void
    {
        $ownerToken = $this->ownerToken('Salao Fila Conflito', 'owner-fila-conflito@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $existingClient = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Joao Ribeiro',
            'phone' => '11988880004',
        ])->assertCreated()->json('id');

        $startsAt = now()->addHours(2);
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $existingClient,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => $startsAt,
        ])->assertCreated();

        $customerToken = $this->customerToken($ownerToken, 'carlos-fila-conflito@example.com');
        $entryId = $this->actingWithToken($customerToken)->postJson('/api/waitlist', [
            'service_id' => $service,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson("/api/waitlist/{$entryId}/assign", [
            'professional_id' => $professional,
            'starts_at' => $startsAt,
        ])->assertStatus(422);
    }

    public function test_customer_can_cancel_own_waitlist_entry_but_not_someone_elses(): void
    {
        $ownerToken = $this->ownerToken('Salao Cancela Fila', 'owner-cancela-fila@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $customerToken = $this->customerToken($ownerToken, 'carlos-cancela-fila@example.com');
        $outroClienteToken = $this->customerToken($ownerToken, 'joao-cancela-fila@example.com');

        $entryId = $this->actingWithToken($customerToken)->postJson('/api/waitlist', [
            'service_id' => $service,
        ])->assertCreated()->json('id');

        $this->actingWithToken($outroClienteToken)->patchJson("/api/waitlist/{$entryId}", [
            'status' => 'canceled',
        ])->assertForbidden();

        $this->actingWithToken($customerToken)->patchJson("/api/waitlist/{$entryId}", [
            'status' => 'canceled',
        ])->assertOk()->assertJsonPath('status', 'canceled');

        // Entrada ja cancelada nao pode ser atribuida.
        $this->actingWithToken($ownerToken)->postJson("/api/waitlist/{$entryId}/assign", [
            'professional_id' => 1,
            'starts_at' => now()->addHours(2),
        ])->assertStatus(422);
    }

    public function test_staff_creates_waitlist_entry_on_behalf_of_walk_in_client(): void
    {
        $ownerToken = $this->ownerToken('Salao Fila Staff', 'owner-fila-staff@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Joao Ribeiro',
            'phone' => '11988880005',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/waitlist', [
            'client_id' => $client,
            'service_id' => $service,
        ])->assertCreated()->assertJsonPath('status', 'waiting');
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
