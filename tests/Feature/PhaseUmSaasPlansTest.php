<?php

namespace Tests\Feature;

use App\Models\SaasSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseUmSaasPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_starts_trial_with_premium_limits(): void
    {
        $response = $this->postJson('/api/auth/register-owner', [
            'tenant' => ['name' => 'Salao Trial', 'business_type' => 'barbershop'],
            'owner' => ['name' => 'Dono', 'email' => 'owner-trial@example.com', 'password' => 'password123'],
        ])->assertCreated();

        $subscription = $response->json('tenant.saas_subscription');

        $this->assertSame('trial', $subscription['status']);
        $this->assertSame('trial', $subscription['effective_status']);
        $this->assertSame(30, $subscription['trial_days_remaining']);
        $this->assertSame(['professionals' => 3, 'client_subscriptions' => 20, 'units' => 1], $subscription['limits']);
        $this->assertSame(['professionals' => 0, 'client_subscriptions' => 0, 'units' => 1], $subscription['usage']);
    }

    public function test_owner_lists_paid_plans_and_switches_plan(): void
    {
        $ownerToken = $this->ownerToken('Salao Troca Plano', 'owner-troca-plano@example.com');

        $plans = $this->actingWithToken($ownerToken)->getJson('/api/saas-plans')->assertOk();
        $plans->assertJsonCount(3);
        $plans->assertJsonPath('0.code', 'basico');
        $plans->assertJsonPath('1.code', 'intermediario');
        $plans->assertJsonPath('2.code', 'premium');

        $updated = $this->actingWithToken($ownerToken)->patchJson('/api/saas-subscription', [
            'plan_code' => 'basico',
        ])->assertOk();

        $updated->assertJsonPath('saas_subscription.status', 'active');
        $updated->assertJsonPath('saas_subscription.plan.code', 'basico');
        $updated->assertJsonPath('saas_subscription.price_cents', 7999);
        $updated->assertJsonPath('saas_subscription.limits.professionals', 3);
    }

    public function test_professional_and_customer_cannot_manage_saas_plan(): void
    {
        $ownerToken = $this->ownerToken('Salao Restricao Plano', 'owner-restricao-plano@example.com');

        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-restricao-plano@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-restricao-plano@example.com',
            'password' => 'senhaforte1',
        ])->json('token');
        $this->assertNotNull($professionalId);

        $this->actingWithToken($professionalToken)->getJson('/api/saas-plans')->assertForbidden();
        $this->actingWithToken($professionalToken)->patchJson('/api/saas-subscription', ['plan_code' => 'basico'])->assertForbidden();

        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988887777',
            'email' => 'carlos-restricao-plano@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $customerToken = $this->postJson('/api/auth/login', [
            'email' => 'carlos-restricao-plano@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $this->actingWithToken($customerToken)->getJson('/api/saas-plans')->assertForbidden();
        $this->actingWithToken($customerToken)->patchJson('/api/saas-subscription', ['plan_code' => 'basico'])->assertForbidden();
    }

    public function test_professional_limit_is_enforced_on_current_plan(): void
    {
        $ownerToken = $this->ownerToken('Salao Limite Profissional', 'owner-limite-prof@example.com');

        $this->actingWithToken($ownerToken)->patchJson('/api/saas-subscription', ['plan_code' => 'basico'])->assertOk();

        // Basico permite ate 3 profissionais.
        foreach (['Ana', 'Bia', 'Carla'] as $name) {
            $this->actingWithToken($ownerToken)->postJson('/api/professionals', ['name' => $name])
                ->assertCreated();
        }

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', ['name' => 'Excedente'])
            ->assertStatus(422);
    }

    public function test_client_subscription_limit_is_enforced_on_current_plan(): void
    {
        $ownerToken = $this->ownerToken('Salao Limite Cliente', 'owner-limite-cliente@example.com');

        $this->actingWithToken($ownerToken)->patchJson('/api/saas-subscription', ['plan_code' => 'basico'])->assertOk();

        $plan = $this->actingWithToken($ownerToken)->postJson('/api/subscription-plans', [
            'name' => 'Bronze',
            'price_cents' => 9990,
        ])->assertCreated()->json('id');

        // Basico permite ate 100 assinantes ativos: preenche o limite e confirma que
        // o 101o e rejeitado.
        for ($i = 0; $i < 100; $i++) {
            $clientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
                'name' => "Cliente {$i}",
                'phone' => '119'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            ])->assertCreated()->json('id');

            $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
                'client_id' => $clientId,
                'subscription_plan_id' => $plan,
                'starts_on' => now()->toDateString(),
            ])->assertCreated();
        }

        $extraClientId = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Excedente',
            'phone' => '11999999999',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/client-subscriptions', [
            'client_id' => $extraClientId,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_downgrade_deactivates_oldest_excess_but_never_deletes(): void
    {
        $ownerToken = $this->ownerToken('Salao Downgrade', 'owner-downgrade@example.com');

        // Trial libera ate 3 profissionais; sobe pra intermediario (limite 8) pra criar 5.
        $this->actingWithToken($ownerToken)->patchJson('/api/saas-subscription', ['plan_code' => 'intermediario'])->assertOk();

        $professionalIds = [];
        foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $name) {
            $professionalIds[] = $this->actingWithToken($ownerToken)->postJson('/api/professionals', ['name' => $name])
                ->assertCreated()->json('id');
        }

        // Basico permite so 3: os 3 mais antigos (P1, P2, P3) continuam ativos, P4 e P5 sao desativados.
        $this->actingWithToken($ownerToken)->patchJson('/api/saas-subscription', ['plan_code' => 'basico'])->assertOk();

        $professionals = $this->actingWithToken($ownerToken)->getJson('/api/professionals')->assertOk()->json();
        $byId = collect($professionals)->keyBy('id');

        $this->assertTrue($byId[$professionalIds[0]]['is_active']);
        $this->assertTrue($byId[$professionalIds[1]]['is_active']);
        $this->assertTrue($byId[$professionalIds[2]]['is_active']);
        $this->assertFalse($byId[$professionalIds[3]]['is_active']);
        $this->assertFalse($byId[$professionalIds[4]]['is_active']);

        // Nada foi removido: os 5 continuam existindo.
        $this->assertCount(5, $professionals);
    }

    public function test_expired_trial_blocks_writes_but_not_reads(): void
    {
        $ownerToken = $this->ownerToken('Salao Trial Vencido', 'owner-trial-vencido@example.com');

        SaasSubscription::query()->update(['trial_ends_at' => now()->subDay()]);

        // Leitura continua liberada.
        $this->actingWithToken($ownerToken)->getJson('/api/tenant')->assertOk()
            ->assertJsonPath('saas_subscription.effective_status', 'trial_expired');
        $this->actingWithToken($ownerToken)->getJson('/api/clients')->assertOk();

        // Escrita e bloqueada com 402.
        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Bloqueado',
            'phone' => '11999998888',
        ])->assertStatus(402);

        // A saida (trocar de plano) continua sempre liberada.
        $this->actingWithToken($ownerToken)->patchJson('/api/saas-subscription', ['plan_code' => 'basico'])
            ->assertOk()
            ->assertJsonPath('saas_subscription.effective_status', 'active');

        // Depois de escolher um plano, a escrita volta a funcionar.
        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Cliente Liberado',
            'phone' => '11999997777',
        ])->assertCreated();
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
