<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroBusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_without_business_hours_configured_allows_any_time(): void
    {
        $ownerToken = $this->ownerToken('Salao Sem Horario', 'owner-sem-horario@example.com');
        [$professional, $service, $client] = $this->setupCatalog($ownerToken, 'sem-horario');

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(3, 0),
        ])->assertCreated();
    }

    public function test_booking_rejected_outside_configured_business_hours(): void
    {
        $ownerToken = $this->ownerToken('Salao Com Horario', 'owner-com-horario@example.com');
        [$professional, $service, $client] = $this->setupCatalog($ownerToken, 'com-horario');

        $this->actingWithToken($ownerToken)->patchJson('/api/tenant', [
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ])->assertOk();

        // Antes de abrir.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(8, 0),
        ])->assertStatus(422);

        // Depois de fechar (servico de 30min terminaria as 18:15).
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(17, 45),
        ])->assertStatus(422);

        // Dentro do horario.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated();
    }

    public function test_booking_rejected_during_break_period(): void
    {
        $ownerToken = $this->ownerToken('Salao Com Pausa', 'owner-com-pausa@example.com');
        [$professional, $service, $client] = $this->setupCatalog($ownerToken, 'com-pausa');

        $this->actingWithToken($ownerToken)->patchJson('/api/tenant', [
            'opening_time' => '09:00',
            'closing_time' => '20:00',
            'break_start_time' => '12:00',
            'break_end_time' => '14:00',
        ])->assertOk();

        // Comeca dentro da pausa.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(12, 30),
        ])->assertStatus(422);

        // Termina dentro da pausa (comeca as 11:45, servico de 30min termina 12:15).
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(11, 45),
        ])->assertStatus(422);

        // Antes da pausa, ok.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertCreated();

        // Depois da pausa, ok.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(14, 0),
        ])->assertCreated();
    }

    public function test_booking_rejected_on_date_marked_fully_closed_by_override(): void
    {
        $ownerToken = $this->ownerToken('Salao Feriado', 'owner-feriado@example.com');
        [$professional, $service, $client] = $this->setupCatalog($ownerToken, 'feriado');

        $this->actingWithToken($ownerToken)->patchJson('/api/tenant', [
            'opening_time' => '09:00',
            'closing_time' => '20:00',
        ])->assertOk();

        $closedDate = now()->addDay()->toDateString();

        $this->actingWithToken($ownerToken)->postJson('/api/tenant/schedule-overrides', [
            'date' => $closedDate,
            'is_closed' => true,
        ])->assertCreated();

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertStatus(422);

        // No dia seguinte (sem excecao) continua liberado.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDays(2)->setTime(10, 0),
        ])->assertCreated();
    }

    public function test_booking_respects_custom_closing_time_override_for_specific_date(): void
    {
        $ownerToken = $this->ownerToken('Salao Fecha Mais Cedo', 'owner-fecha-cedo@example.com');
        [$professional, $service, $client] = $this->setupCatalog($ownerToken, 'fecha-cedo');

        $this->actingWithToken($ownerToken)->patchJson('/api/tenant', [
            'opening_time' => '09:00',
            'closing_time' => '20:00',
        ])->assertOk();

        $earlyCloseDate = now()->addDay()->toDateString();

        $this->actingWithToken($ownerToken)->postJson('/api/tenant/schedule-overrides', [
            'date' => $earlyCloseDate,
            'opens_at' => '09:00',
            'closes_at' => '15:00',
        ])->assertCreated();

        // 16h estaria dentro do horario padrao, mas o salao fechou mais cedo nesse dia.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(16, 0),
        ])->assertStatus(422);

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertCreated();
    }

    public function test_waitlist_assign_also_respects_business_hours(): void
    {
        $ownerToken = $this->ownerToken('Salao Fila Horario', 'owner-fila-horario@example.com');
        [$professional, $service, $client] = $this->setupCatalog($ownerToken, 'fila-horario');

        $this->actingWithToken($ownerToken)->patchJson('/api/tenant', [
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ])->assertOk();

        $entry = $this->actingWithToken($ownerToken)->postJson('/api/waitlist', [
            'client_id' => $client,
            'service_id' => $service,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson("/api/waitlist/{$entry}/assign", [
            'professional_id' => $professional,
            'starts_at' => now()->addDay()->setTime(19, 0),
        ])->assertStatus(422);

        $this->actingWithToken($ownerToken)->postJson("/api/waitlist/{$entry}/assign", [
            'professional_id' => $professional,
            'starts_at' => now()->addDay()->setTime(9, 30),
        ])->assertOk();
    }

    public function test_owner_can_list_and_delete_schedule_overrides(): void
    {
        $ownerToken = $this->ownerToken('Salao Excecoes', 'owner-excecoes@example.com');

        $override = $this->actingWithToken($ownerToken)->postJson('/api/tenant/schedule-overrides', [
            'date' => now()->addDay()->toDateString(),
            'is_closed' => true,
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->getJson('/api/tenant/schedule-overrides')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingWithToken($ownerToken)->deleteJson("/api/tenant/schedule-overrides/{$override}")
            ->assertNoContent();

        $this->actingWithToken($ownerToken)->getJson('/api/tenant/schedule-overrides')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_professional_and_customer_cannot_write_schedule_overrides_but_can_read(): void
    {
        $ownerToken = $this->ownerToken('Salao Excecoes Restrito', 'owner-excecoes-restrito@example.com');
        $customerToken = $this->customerToken($ownerToken, 'cliente-excecoes@example.com');

        $this->actingWithToken($customerToken)->postJson('/api/tenant/schedule-overrides', [
            'date' => now()->addDay()->toDateString(),
            'is_closed' => true,
        ])->assertForbidden();

        $override = $this->actingWithToken($ownerToken)->postJson('/api/tenant/schedule-overrides', [
            'date' => now()->addDay()->toDateString(),
            'is_closed' => true,
        ])->assertCreated()->json('id');

        // Leitura precisa ficar liberada: e o que a tela de agendamento do
        // cliente usa pra montar os horarios disponiveis (bug real reportado
        // pelo usuario — cliente recebia 403 ao tentar agendar).
        $this->actingWithToken($customerToken)->getJson('/api/tenant/schedule-overrides')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingWithToken($customerToken)->deleteJson("/api/tenant/schedule-overrides/{$override}")
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int, 2: int} [professionalId, serviceId, clientId]
     */
    private function setupCatalog(string $ownerToken, string $suffix): array
    {
        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '119'.random_int(10000000, 99999999),
        ])->assertCreated()->json('id');

        return [$professional, $service, $client];
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
