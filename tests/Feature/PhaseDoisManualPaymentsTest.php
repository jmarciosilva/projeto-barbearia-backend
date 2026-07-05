<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseDoisManualPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_confirms_manual_payment_with_supported_paid_methods(): void
    {
        $ownerToken = $this->ownerToken('Salao Modalidades', 'owner-modalidades@example.com');

        foreach (['pix', 'credit_card', 'debit_card', 'cash'] as $method) {
            $paymentId = $this->pendingSubscriptionPayment($ownerToken, $method);

            $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
                'method' => $method,
            ])->assertOk()
                ->assertJsonPath('method', $method)
                ->assertJsonPath('status', 'paid');

            $this->assertDatabaseHas('payments', [
                'id' => $paymentId,
                'method' => $method,
                'status' => 'paid',
            ]);
        }
    }

    public function test_fiado_registers_method_but_keeps_payment_pending(): void
    {
        $ownerToken = $this->ownerToken('Salao Fiado', 'owner-fiado@example.com');
        $paymentId = $this->pendingSubscriptionPayment($ownerToken, 'pix');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'fiado',
        ])->assertOk()
            ->assertJsonPath('method', 'fiado')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('paid_at', null);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'method' => 'fiado',
            'status' => 'pending',
            'paid_at' => null,
        ]);
    }

    public function test_owner_can_receive_fiado_in_partial_payments_until_fully_paid(): void
    {
        $ownerToken = $this->ownerToken('Salao Fiado Parcial', 'owner-fiado-parcial@example.com');
        $paymentId = $this->pendingSubscriptionPayment($ownerToken, 'pix');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'fiado',
        ])->assertOk()->assertJsonPath('status', 'pending');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/receipts", [
            'amount_cents' => 4000,
            'method' => 'pix',
        ])->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('received_cents', 4000)
            ->assertJsonPath('remaining_cents', 5990);

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/receipts", [
            'amount_cents' => 5990,
            'method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('received_cents', 9990)
            ->assertJsonPath('remaining_cents', 0);
    }

    private function pendingSubscriptionPayment(string $ownerToken, string $method): int
    {
        $serviceId = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Servico '.$method.random_int(1000, 9999),
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $planId = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Plano '.$method.random_int(1000, 9999),
            'price_cents' => 9990,
            'services' => [['id' => $serviceId]],
        ])->assertCreated()->json('id');

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente '.$method.random_int(1000, 9999),
            'phone' => '119'.random_int(10000000, 99999999),
        ])->assertCreated()->json('id');

        $subscriptionId = $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $clientId,
            'subscription_plan_id' => $planId,
            'starts_on' => now()->toDateString(),
        ])->assertCreated()->json('id');

        return $this->actingWithToken($ownerToken)->postJson('/api/payments', [
            'client_subscription_id' => $subscriptionId,
            'amount_cents' => 9990,
            'method' => $method === 'fiado' ? 'pix' : $method,
            'status' => 'pending',
        ])->assertCreated()->json('id');
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
