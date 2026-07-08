<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseDoisProfessionalFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_sees_commission_statement_with_advances(): void
    {
        $ownerToken = $this->ownerToken('Salao Comissao', 'owner-comissao@example.com');
        [$professionalId, $professionalToken] = $this->professionalWithCompletedAppointments($ownerToken);

        $this->actingWithToken($ownerToken)->patchJson('/api/tenant', [
            'professional_payment_day' => 10,
        ])->assertOk()->assertJsonPath('professional_payment_day', 10);

        $this->actingWithToken($ownerToken)->postJson("/api/professionals/{$professionalId}/advances", [
            'amount_cents' => 2000,
            'notes' => 'Vale transporte',
        ])->assertCreated();

        $statement = $this->actingWithToken($professionalToken)
            ->getJson('/api/me/professional/finance?period=month')
            ->assertOk();

        $statement->assertJsonPath('completed_count', 2);
        $statement->assertJsonPath('gross_cents', 12000);
        $statement->assertJsonPath('commission_percentage', 50);
        $statement->assertJsonPath('commission_cents', 6000);
        $statement->assertJsonPath('advances_cents', 2000);
        $statement->assertJsonPath('net_cents', 4000);
        $statement->assertJsonPath('payment_day', 10);
    }

    public function test_professional_statement_splits_avulso_and_plano_appointments(): void
    {
        $ownerToken = $this->ownerToken('Salao Avulso Plano', 'owner-avulso-plano@example.com');

        $serviceId = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $planId = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
            'services' => [['id' => $serviceId]],
        ])->assertCreated()->json('id');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-avulso-plano@example.com',
            'password' => 'senhaforte1',
            'commission_percentage' => 50,
        ])->assertCreated()->json('id');

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Avulso Plano',
            'phone' => '11988881112',
        ])->assertCreated()->json('id');

        $subscriptionId = $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $clientId,
            'subscription_plan_id' => $planId,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        // Um atendimento avulso (sem assinatura) e um pelo plano.
        $avulsoAppointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'service_id' => $serviceId,
            'starts_at' => now()->startOfMonth()->addDays(2),
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$avulsoAppointmentId}/complete")->assertOk();

        $planoAppointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'service_id' => $serviceId,
            'client_subscription_id' => $subscriptionId,
            'starts_at' => now()->startOfMonth()->addDays(3),
        ])->assertCreated()->json('id');
        $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$planoAppointmentId}/complete")->assertOk();

        $statement = $this->actingWithToken($ownerToken)
            ->getJson("/api/professionals/{$professionalId}/finance?period=month")
            ->assertOk();

        $statement->assertJsonPath('completed_count', 2);
        $statement->assertJsonPath('avulso_count', 1);
        $statement->assertJsonPath('plano_count', 1);
        $statement->assertJsonPath('gross_cents', 12000);
        $statement->assertJsonPath('avulso_revenue_cents', 6000);
        $statement->assertJsonPath('plano_revenue_cents', 6000);
    }

    private function professionalWithCompletedAppointments(string $ownerToken): array
    {
        $serviceId = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-comissao@example.com',
            'password' => 'senhaforte1',
            'commission_percentage' => 50,
        ])->assertCreated()->json('id');

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Comissao',
            'phone' => '11988881111',
        ])->assertCreated()->json('id');

        foreach ([now()->startOfMonth()->addDays(2), now()->startOfMonth()->addDays(3)] as $startsAt) {
            $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
                'client_id' => $clientId,
                'professional_id' => $professionalId,
                'service_id' => $serviceId,
                'starts_at' => $startsAt,
            ])->assertCreated()->json('id');

            $this->actingWithToken($ownerToken)->postJson("/api/appointments/{$appointmentId}/complete")
                ->assertOk();
        }

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-comissao@example.com',
            'password' => 'senhaforte1',
        ])->assertOk()->json('token');

        return [$professionalId, $professionalToken];
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
