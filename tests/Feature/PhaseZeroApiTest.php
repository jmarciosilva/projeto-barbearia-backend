<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_configure_plan_subscription_and_appointment(): void
    {
        $register = $this->postJson('/api/auth/register-owner', [
            'tenant' => [
                'name' => 'Barbearia Piloto',
                'business_type' => 'barbershop',
                'phone' => '11999990000',
            ],
            'owner' => [
                'name' => 'Jose Silva',
                'email' => 'jose@example.com',
                'password' => 'password123',
            ],
        ])->assertCreated();

        $token = $register->json('token');

        $service = $this->withToken($token)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 45,
            'price_cents' => 6000,
        ])->assertCreated();

        $plan = $this->withToken($token)->postJson('/api/subscription-plans', [
            'name' => 'Plano Bronze',
            'price_cents' => 9990,
            'usage_limit' => 4,
            'allowed_weekdays' => [1, 2, 3, 4, 5],
            'allowed_start_time' => '08:00',
            'allowed_end_time' => '18:00',
            'services' => [
                ['id' => $service->json('id')],
            ],
        ])->assertCreated();

        $this->withToken($token)->patchJson('/api/subscription-plans/'.$plan->json('id'), [
            'description' => 'Corte de segunda a sexta.',
        ])->assertOk();

        $client = $this->withToken($token)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988887777',
        ])->assertCreated();

        $professional = $this->withToken($token)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'specialty' => 'Cortes',
        ])->assertCreated();

        $subscription = $this->withToken($token)->postJson('/api/client-subscriptions', [
            'client_id' => $client->json('id'),
            'subscription_plan_id' => $plan->json('id'),
            'starts_on' => '2026-07-01',
            'renews_on' => '2026-08-01',
            'payment_status' => 'paid',
        ])->assertCreated();

        $appointment = $this->withToken($token)->postJson('/api/appointments', [
            'client_id' => $client->json('id'),
            'professional_id' => $professional->json('id'),
            'service_id' => $service->json('id'),
            'client_subscription_id' => $subscription->json('id'),
            'starts_at' => '2026-07-06 10:00:00',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/appointments', [
            'client_id' => $client->json('id'),
            'professional_id' => $professional->json('id'),
            'service_id' => $service->json('id'),
            'starts_at' => '2026-07-06 10:15:00',
        ])->assertUnprocessable();

        $this->withToken($token)
            ->postJson('/api/appointments/'.$appointment->json('id').'/complete')
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonCount(1, 'subscription.usages');
    }

    public function test_plan_creation_rolls_back_when_service_is_invalid(): void
    {
        $token = $this->ownerToken('Primeiro Salao', 'primeiro@example.com');

        $this->withToken($token)->postJson('/api/subscription-plans', [
            'name' => 'Plano com servico invalido',
            'price_cents' => 9900,
            'services' => [
                ['id' => 999999],
            ],
        ])->assertUnprocessable();

        $this->assertSame(0, SubscriptionPlan::where('name', 'Plano com servico invalido')->count());
    }

    public function test_api_errors_are_returned_as_json(): void
    {
        $token = $this->ownerToken('Salao JSON', 'json@example.com');

        $this->withToken($token)
            ->patchJson('/api/services/999999', ['name' => 'Nao existe'])
            ->assertNotFound()
            ->assertJson([
                'message' => 'Registro nao encontrado.',
                'error' => 'not_found',
            ]);
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
}
