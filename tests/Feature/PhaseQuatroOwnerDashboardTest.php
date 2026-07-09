<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseQuatroOwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_summary_reports_todays_counts_and_revenue(): void
    {
        $ownerToken = $this->ownerToken('Salao Painel Inteligente', 'owner-painel@example.com');

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
            'phone' => '11988880010',
        ])->assertCreated()->json('id');

        // Avulso pendente: gera Payment status=pending automaticamente.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->setTime(9, 0),
        ])->assertCreated();

        // Avulso confirmado: pagamento marcado como pago.
        $confirmedAppointment = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->setTime(10, 0),
        ])->assertCreated();
        $confirmedPaymentId = $confirmedAppointment->json('payment.id');
        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$confirmedPaymentId}/mark-paid", [
            'method' => 'pix',
        ])->assertOk();

        // Cancelado.
        $canceledAppointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->setTime(11, 0),
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->patchJson("/api/appointments/{$canceledAppointmentId}", [
            'status' => 'canceled',
        ])->assertOk();

        // Fila de espera.
        $customerToken = $this->customerToken($ownerToken, 'carlos-painel@example.com');
        $this->actingWithToken($customerToken)->postJson('/api/waitlist', [
            'service_id' => $service,
        ])->assertCreated();

        // Assinatura ativa (receita recorrente).
        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated();

        // Fiado com recebimento parcial (ontem, pra nao afetar as metricas de hoje):
        // deve contar so o saldo restante, nao o valor cheio.
        $debtAppointment = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->subDay()->setTime(9, 0),
        ])->assertCreated();
        $debtPaymentId = $debtAppointment->json('payment.id');
        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$debtPaymentId}/mark-paid", [
            'method' => 'fiado',
        ])->assertOk();
        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$debtPaymentId}/receipts", [
            'amount_cents' => 2000,
            'method' => 'pix',
        ])->assertOk();

        $summary = $this->actingWithToken($ownerToken)->getJson('/api/dashboard/summary')->assertOk();

        $summary->assertJsonPath('appointments_today', 3);
        $summary->assertJsonPath('confirmed_today', 1);
        $summary->assertJsonPath('pending_today', 1);
        $summary->assertJsonPath('canceled_today', 1);
        $summary->assertJsonPath('waitlist_count', 1);
        $summary->assertJsonPath('expected_revenue_today_cents', 12000);
        $summary->assertJsonPath('recurring_revenue_month_cents', 9990);
        $summary->assertJsonPath('walkin_revenue_month_cents', 6000);
        // 6000 (preco do servico) - 2000 (recebido parcial) = 4000 em aberto.
        // O avulso pendente comum (linha ~32, method=pix default) nao entra
        // nessa soma, so o que o dono marcou como fiado de fato.
        $summary->assertJsonPath('open_debt_cents', 4000);
    }

    public function test_occupancy_uses_professional_working_hours_and_this_weeks_appointments(): void
    {
        $ownerToken = $this->ownerToken('Salao Ocupacao', 'owner-ocupacao@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 60,
        ])->assertCreated()->json('id');

        $monday = now()->startOfWeek();

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'working_hours' => [
                ['weekday' => $monday->dayOfWeek, 'starts_at' => '09:00', 'ends_at' => '13:00'],
            ],
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880011',
        ])->assertCreated()->json('id');

        // 2 horas ocupadas dentro de 4 horas de expediente (09h-13h) = 50%.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => $monday->copy()->setTime(9, 0),
        ])->assertCreated();
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => $monday->copy()->setTime(10, 0),
        ])->assertCreated();

        $occupancy = $this->actingWithToken($ownerToken)->getJson('/api/dashboard/occupancy')->assertOk();

        $occupancy->assertJsonPath('0.professional_name', 'Ana Souza');
        $occupancy->assertJsonPath('0.days.0.weekday', $monday->dayOfWeek);
        $occupancy->assertJsonPath('0.days.0.date', $monday->toDateString());
        $occupancy->assertJsonPath('0.days.0.has_override', false);
        $occupancy->assertJsonPath('0.days.0.available_minutes', 240);
        $occupancy->assertJsonPath('0.days.0.occupied_minutes', 120);
        $occupancy->assertJsonPath('0.days.0.percentage', 50);
    }

    public function test_professional_registers_and_deletes_own_schedule_override(): void
    {
        $ownerToken = $this->ownerToken('Salao Ajuste Horario', 'owner-ajuste-horario@example.com');

        [$professionalToken, ] = $this->professionalWithLogin($ownerToken, 'ana-ajuste@example.com');
        $today = now()->toDateString();

        $created = $this->actingWithToken($professionalToken)->postJson(
            '/api/me/professional/schedule-overrides',
            ['date' => $today, 'starts_at' => '10:00', 'ends_at' => '18:00']
        )->assertCreated();

        $created->assertJsonPath('date', $today);
        $created->assertJsonPath('starts_at', '10:00');
        $overrideId = $created->json('id');

        $this->actingWithToken($professionalToken)
            ->getJson('/api/me/professional/schedule-overrides')
            ->assertOk()
            ->assertJsonCount(1);

        // Outro profissional nao pode apagar o ajuste alheio.
        [$otherProfessionalToken, ] = $this->professionalWithLogin($ownerToken, 'joao-ajuste@example.com');
        $this->actingWithToken($otherProfessionalToken)
            ->deleteJson("/api/me/professional/schedule-overrides/{$overrideId}")
            ->assertNotFound();

        $this->actingWithToken($professionalToken)
            ->deleteJson("/api/me/professional/schedule-overrides/{$overrideId}")
            ->assertNoContent();

        $this->actingWithToken($professionalToken)
            ->getJson('/api/me/professional/schedule-overrides')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_occupancy_uses_schedule_override_instead_of_recurring_hours(): void
    {
        $ownerToken = $this->ownerToken('Salao Ocupacao Ajuste', 'owner-ocupacao-ajuste@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 60,
        ])->assertCreated()->json('id');

        $monday = now()->startOfWeek();

        [$professionalToken, $professionalId] = $this->professionalWithLogin(
            $ownerToken,
            'ana-ocupacao-ajuste@example.com',
            workingHours: [
                ['weekday' => $monday->dayOfWeek, 'starts_at' => '08:00', 'ends_at' => '18:00'],
            ],
        );

        // Profissional chegou as 10h em vez das 8h de costume.
        $this->actingWithToken($professionalToken)->postJson(
            '/api/me/professional/schedule-overrides',
            ['date' => $monday->toDateString(), 'starts_at' => '10:00', 'ends_at' => '14:00']
        )->assertCreated();

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880021',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professionalId,
            'service_id' => $service,
            'starts_at' => $monday->copy()->setTime(11, 0),
        ])->assertCreated();

        $occupancy = $this->actingWithToken($ownerToken)->getJson('/api/dashboard/occupancy')->assertOk();

        $occupancy->assertJsonPath('0.days.0.has_override', true);
        $occupancy->assertJsonPath('0.days.0.available_minutes', 240);
        $occupancy->assertJsonPath('0.days.0.occupied_minutes', 60);
        $occupancy->assertJsonPath('0.days.0.percentage', 25);
    }

    public function test_occupancy_skips_day_marked_off_by_schedule_override(): void
    {
        $ownerToken = $this->ownerToken('Salao Ocupacao Folga', 'owner-ocupacao-folga@example.com');

        $monday = now()->startOfWeek();

        [$professionalToken, ] = $this->professionalWithLogin(
            $ownerToken,
            'ana-ocupacao-folga@example.com',
            workingHours: [
                ['weekday' => $monday->dayOfWeek, 'starts_at' => '08:00', 'ends_at' => '18:00'],
            ],
        );

        $this->actingWithToken($professionalToken)->postJson(
            '/api/me/professional/schedule-overrides',
            ['date' => $monday->toDateString(), 'is_off' => true]
        )->assertCreated();

        $occupancy = $this->actingWithToken($ownerToken)->getJson('/api/dashboard/occupancy')->assertOk();

        $occupancy->assertJsonCount(0, '0.days');
    }

    public function test_team_performance_aggregates_completed_appointments_per_professional(): void
    {
        $ownerToken = $this->ownerToken('Salao Desempenho', 'owner-desempenho@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $topProfessionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'commission_percentage' => 50,
        ])->assertCreated()->json('id');

        $otherProfessionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
            'commission_percentage' => 40,
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880030',
        ])->assertCreated()->json('id');

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');
        $subscriptionId = $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        // Ana: 2 avulsos + 1 plano concluidos este mes = 3 atendimentos, 18000 centavos.
        foreach ([2, 3] as $day) {
            $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
                'client_id' => $client,
                'professional_id' => $topProfessionalId,
                'service_id' => $service,
                'starts_at' => now()->startOfMonth()->addDays($day),
            ])->assertCreated()->json('id');
            $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$appointmentId}/complete")->assertOk();
        }
        $planoAppointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $topProfessionalId,
            'service_id' => $service,
            'client_subscription_id' => $subscriptionId,
            'starts_at' => now()->startOfMonth()->addDays(4),
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$planoAppointmentId}/complete")->assertOk();

        // Rafael: 1 avulso concluido este mes = 6000 centavos.
        $rafaelAppointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $otherProfessionalId,
            'service_id' => $service,
            'starts_at' => now()->startOfMonth()->addDays(5),
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$rafaelAppointmentId}/complete")->assertOk();

        // Agendamento futuro (nao concluido) nao deve contar para nenhum dos dois.
        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $otherProfessionalId,
            'service_id' => $service,
            'starts_at' => now()->startOfMonth()->addDays(6),
        ])->assertCreated();

        // Adiantamento de Ana este mes: entra no valor a receber (9000 - 3000 = 6000).
        $this->actingWithToken($ownerToken)->postJson("/api/professionals/{$topProfessionalId}/advances", [
            'amount_cents' => 3000,
        ])->assertCreated();

        $performance = $this->actingWithToken($ownerToken)->getJson('/api/dashboard/team-performance')->assertOk();

        // Ordenado por receita gerada (decrescente): Ana primeiro.
        $performance->assertJsonPath('0.professional_name', 'Ana Souza');
        $performance->assertJsonPath('0.completed_count', 3);
        $performance->assertJsonPath('0.avulso_count', 2);
        $performance->assertJsonPath('0.plano_count', 1);
        $performance->assertJsonPath('0.gross_cents', 18000);
        $performance->assertJsonPath('0.commission_percentage', 50);
        $performance->assertJsonPath('0.commission_cents', 9000);
        $performance->assertJsonPath('0.advances_cents', 3000);
        $performance->assertJsonPath('0.net_cents', 6000);

        $performance->assertJsonPath('1.professional_name', 'Rafael Souza');
        $performance->assertJsonPath('1.completed_count', 1);
        $performance->assertJsonPath('1.avulso_count', 1);
        $performance->assertJsonPath('1.plano_count', 0);
        $performance->assertJsonPath('1.gross_cents', 6000);
        $performance->assertJsonPath('1.commission_cents', 2400);
        $performance->assertJsonPath('1.advances_cents', 0);
        $performance->assertJsonPath('1.net_cents', 2400);
    }

    public function test_team_performance_excludes_inactive_professionals(): void
    {
        $ownerToken = $this->ownerToken('Salao Desempenho Inativo', 'owner-desempenho-inativo@example.com');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->patchJson("/api/professionals/{$professionalId}", [
            'is_active' => false,
        ])->assertOk();

        $this->actingWithToken($ownerToken)->getJson('/api/dashboard/team-performance')
            ->assertOk()
            ->assertJsonCount(0);
    }

    private function professionalWithLogin(string $ownerToken, string $email, array $workingHours = []): array
    {
        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => $email,
            'password' => 'senhaforte1',
            'working_hours' => $workingHours,
        ])->assertCreated()->json('id');

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'senhaforte1',
        ])->assertOk()->json('token');

        return [$professionalToken, $professionalId];
    }

    public function test_return_risk_flags_client_at_typical_return_window(): void
    {
        $ownerToken = $this->ownerToken('Salao Retorno', 'owner-retorno@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Maria Oliveira',
            'phone' => '11988880012',
        ])->assertCreated()->json('id');

        // Historico: atendimentos a cada 25 dias, ultimo ha 38 dias (razao 1.52 -> alta).
        foreach ([-63, -38] as $daysAgo) {
            $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
                'client_id' => $client,
                'professional_id' => $professional,
                'service_id' => $service,
                'starts_at' => now()->addDays($daysAgo),
            ])->assertCreated()->json('id');

            $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$appointmentId}/complete")
                ->assertOk();
        }

        $risk = $this->actingWithToken($ownerToken)->getJson('/api/dashboard/return-risk')->assertOk();

        $risk->assertJsonPath('0.client_name', 'Maria Oliveira');
        $risk->assertJsonPath('0.avg_interval_days', 25);
        $risk->assertJsonPath('0.days_since_last', 38);
        $risk->assertJsonPath('0.probability', 'alta');
    }

    public function test_return_risk_excludes_clients_with_less_than_two_completed_appointments(): void
    {
        $ownerToken = $this->ownerToken('Salao Retorno Sem Historico', 'owner-retorno-sem-historico@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Joao Ribeiro',
            'phone' => '11988880013',
        ])->assertCreated()->json('id');

        $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->subDays(10),
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$appointmentId}/complete")->assertOk();

        $this->actingWithToken($ownerToken)->getJson('/api/dashboard/return-risk')
            ->assertOk()
            ->assertJsonCount(0);
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

    private function actingWithToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
