<?php

namespace Tests\Feature;

use App\Models\Payment;
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

    public function test_professional_confirms_payment_of_own_avulso_appointment(): void
    {
        $ownerToken = $this->ownerToken('Salao Confirma Profissional', 'owner-confirma-prof@example.com');

        $serviceId = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-confirma-prof@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Avulso Confirma',
            'phone' => '11988883333',
        ])->assertCreated()->json('id');

        $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $clientId,
            'professional_id' => $professionalId,
            'service_id' => $serviceId,
            'starts_at' => now()->addDay(),
        ])->assertCreated()->json('id');

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-confirma-prof@example.com',
            'password' => 'senhaforte1',
        ])->assertOk()->json('token');

        $completed = $this->actingWithToken($professionalToken)
            ->postJson("/api/appointments/{$appointmentId}/complete")
            ->assertOk();

        $paymentId = $completed->json('payment.id');

        $this->actingWithToken($professionalToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'pix',
        ])->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('method', 'pix');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'paid',
            'method' => 'pix',
        ]);
    }

    public function test_professional_cannot_confirm_payment_of_another_professionals_appointment(): void
    {
        $ownerToken = $this->ownerToken('Salao Confirma Cruzado', 'owner-confirma-cruzado@example.com');

        $serviceId = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte',
            'duration_minutes' => 30,
            'price_cents' => 6000,
        ])->assertCreated()->json('id');

        $professionalAId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Profissional A',
            'email' => 'prof-a-confirma@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Profissional B',
            'email' => 'prof-b-confirma@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Avulso Cruzado',
            'phone' => '11988884444',
        ])->assertCreated()->json('id');

        $appointmentId = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $clientId,
            'professional_id' => $professionalAId,
            'service_id' => $serviceId,
            'starts_at' => now()->addDay(),
        ])->assertCreated()->json('id');

        $completed = $this->actingWithToken($ownerToken)
            ->postJson("/api/appointments/{$appointmentId}/complete")
            ->assertOk();

        $paymentId = $completed->json('payment.id');

        $professionalBToken = $this->postJson('/api/auth/login', [
            'email' => 'prof-b-confirma@example.com',
            'password' => 'senhaforte1',
        ])->assertOk()->json('token');

        $this->actingWithToken($professionalBToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'pix',
        ])->assertForbidden();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'pending',
        ]);
    }

    public function test_professional_cannot_confirm_payment_not_tied_to_an_appointment(): void
    {
        $ownerToken = $this->ownerToken('Salao Confirma Assinatura', 'owner-confirma-assinatura@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-confirma-assinatura@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $paymentId = $this->pendingSubscriptionPayment($ownerToken, 'pix');

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-confirma-assinatura@example.com',
            'password' => 'senhaforte1',
        ])->assertOk()->json('token');

        $this->actingWithToken($professionalToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'pix',
        ])->assertForbidden();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'pending',
        ]);
    }

    public function test_owner_edits_amount_and_method_of_a_confirmed_payment(): void
    {
        $ownerToken = $this->ownerToken('Salao Edicao', 'owner-edicao@example.com');
        $paymentId = $this->pendingSubscriptionPayment($ownerToken, 'pix');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'debit_card',
        ])->assertOk();

        $this->actingWithToken($ownerToken)->patchJson("/api/payments/{$paymentId}", [
            'amount_cents' => 8000,
            'method' => 'credit_card',
            'notes' => 'Corrigido apos lancamento duplicado',
        ])->assertOk()
            ->assertJsonPath('amount_cents', 8000)
            ->assertJsonPath('method', 'credit_card')
            ->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'amount_cents' => 8000,
            'method' => 'credit_card',
            'status' => 'paid',
        ]);
    }

    public function test_owner_cannot_edit_amount_below_what_was_already_received(): void
    {
        $ownerToken = $this->ownerToken('Salao Edicao Invalida', 'owner-edicao-invalida@example.com');
        $paymentId = $this->pendingSubscriptionPayment($ownerToken, 'pix');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'fiado',
        ])->assertOk();

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/receipts", [
            'amount_cents' => 4000,
            'method' => 'pix',
        ])->assertOk();

        $this->actingWithToken($ownerToken)->patchJson("/api/payments/{$paymentId}", [
            'amount_cents' => 3000,
        ])->assertStatus(422);
    }

    public function test_deleting_a_duplicate_subscription_payment_keeps_subscription_paid(): void
    {
        // Cenario real reportado pelo usuario: dono lancou o pagamento da
        // mesma assinatura duas vezes (debito e credito por engano).
        // Apagar UM dos dois nao pode derrubar a assinatura pra "pending",
        // porque o outro pagamento continua provando que ela foi paga.
        $ownerToken = $this->ownerToken('Salao Duplicado', 'owner-duplicado@example.com');
        $paymentId = $this->pendingSubscriptionPayment($ownerToken, 'pix');

        $this->actingWithToken($ownerToken)->postJson("/api/payments/{$paymentId}/mark-paid", [
            'method' => 'debit_card',
        ])->assertOk();

        $subscriptionId = Payment::find($paymentId)->client_subscription_id;

        $duplicatePaymentId = $this->actingWithToken($ownerToken)->postJson('/api/payments', [
            'client_subscription_id' => $subscriptionId,
            'amount_cents' => 9990,
            'method' => 'credit_card',
            'status' => 'paid',
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('client_subscriptions', [
            'id' => $subscriptionId,
            'payment_status' => 'paid',
        ]);

        $this->actingWithToken($ownerToken)->deleteJson("/api/payments/{$duplicatePaymentId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('payments', ['id' => $duplicatePaymentId]);
        $this->assertDatabaseHas('client_subscriptions', [
            'id' => $subscriptionId,
            'payment_status' => 'paid',
        ]);

        $this->actingWithToken($ownerToken)->deleteJson("/api/payments/{$paymentId}")
            ->assertNoContent();

        $this->assertDatabaseHas('client_subscriptions', [
            'id' => $subscriptionId,
            'payment_status' => 'pending',
        ]);
    }

    public function test_owner_deletes_an_avulso_payment(): void
    {
        $ownerToken = $this->ownerToken('Salao Exclui Avulso', 'owner-exclui-avulso@example.com');

        $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Avulso Exclui',
            'phone' => '11988885555',
        ])->assertCreated()->json('id');

        $paymentId = $this->actingWithToken($ownerToken)->postJson('/api/payments', [
            'client_id' => $clientId,
            'amount_cents' => 6000,
            'status' => 'paid',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->deleteJson("/api/payments/{$paymentId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
    }

    public function test_editing_or_deleting_a_payment_from_another_tenant_is_not_found(): void
    {
        $ownerAToken = $this->ownerToken('Salao A Pagamento', 'owner-a-pagamento@example.com');
        $ownerBToken = $this->ownerToken('Salao B Pagamento', 'owner-b-pagamento@example.com');
        $paymentId = $this->pendingSubscriptionPayment($ownerAToken, 'pix');

        $this->actingWithToken($ownerBToken)->patchJson("/api/payments/{$paymentId}", [
            'amount_cents' => 1000,
        ])->assertNotFound();

        $this->actingWithToken($ownerBToken)->deleteJson("/api/payments/{$paymentId}")
            ->assertNotFound();
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
