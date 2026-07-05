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
