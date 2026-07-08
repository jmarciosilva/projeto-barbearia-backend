<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroSalonScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_salon_schedule_without_other_clients_data(): void
    {
        $ownerToken = $this->ownerToken('Salao Agenda Publica', 'owner-agenda-publica@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
            'price_cents' => 5000,
        ])->assertCreated()->json('id');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-agenda-publica@example.com',
            'password' => 'senhaforte1',
            'phone' => '11988887777',
            'commission_percentage' => 40,
        ])->assertCreated()->json('id');

        $otherClientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Fulano Sigiloso',
            'phone' => '11988880030',
            'email' => 'fulano-sigiloso@example.com',
            'notes' => 'Alergico a produto X',
        ])->assertCreated()->json('id');

        $startsAt = now()->addDay()->setTime(9, 0);

        $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $otherClientId,
            'professional_id' => $professionalId,
            'service_id' => $service,
            'starts_at' => $startsAt,
        ])->assertCreated();

        $customerToken = $this->customerToken($ownerToken, 'carlos-agenda-publica@example.com');

        $response = $this->actingWithToken($customerToken)->getJson(
            '/api/appointments/salon?from='.now()->toIso8601String().'&to='.now()->addDays(2)->toIso8601String()
        )->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.professional.name', 'Ana Souza');
        $response->assertJsonPath('0.service.name', 'Corte masculino');

        $payload = $response->json();
        $this->assertArrayNotHasKey('client', $payload[0]);
        $this->assertArrayNotHasKey('client_id', $payload[0]);
        $this->assertArrayNotHasKey('notes', $payload[0]);
        $this->assertArrayNotHasKey('email', $payload[0]['professional']);
        $this->assertArrayNotHasKey('phone', $payload[0]['professional']);
        $this->assertArrayNotHasKey('commission_percentage', $payload[0]['professional']);

        foreach ($payload as $entry) {
            $this->assertStringNotContainsString('Fulano', json_encode($entry));
            $this->assertStringNotContainsString('Alergico', json_encode($entry));
        }
    }

    public function test_salon_schedule_excludes_canceled_appointments(): void
    {
        $ownerToken = $this->ownerToken('Salao Agenda Cancelada', 'owner-agenda-cancelada@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880040',
        ])->assertCreated()->json('id');

        $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(9, 0),
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->patchJson("/api/appointments/{$appointmentId}", [
            'status' => 'canceled',
        ])->assertOk();

        $customerToken = $this->customerToken($ownerToken, 'carlos-agenda-cancelada@example.com');

        $this->actingWithToken($customerToken)->getJson('/api/appointments/salon')
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
