<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_created_with_password_can_login(): void
    {
        $ownerToken = $this->ownerToken('Salao Login Profissional', 'owner-login-prof@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'senhaforte1',
        ])->assertOk();

        $this->assertSame('professional', $login->json('user.role'));
    }

    public function test_client_created_with_password_can_login(): void
    {
        $ownerToken = $this->ownerToken('Salao Login Cliente', 'owner-login-client@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988887777',
            'email' => 'carlos@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'carlos@example.com',
            'password' => 'senhaforte1',
        ])->assertOk();

        $this->assertSame('customer', $login->json('user.role'));
    }

    public function test_professional_created_without_password_has_no_login(): void
    {
        $ownerToken = $this->ownerToken('Salao Sem Login', 'owner-no-login@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
            'email' => 'rafael@example.com',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'rafael@example.com',
            'password' => 'qualquer123',
        ])->assertUnprocessable();
    }

    public function test_professional_and_customer_cannot_manage_catalog(): void
    {
        $ownerToken = $this->ownerToken('Salao Papeis', 'owner-papeis@example.com');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-papeis@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880001',
            'email' => 'carlos-papeis@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $professionalAuth = $this->postJson('/api/auth/login', [
            'email' => 'ana-papeis@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $customerAuth = $this->postJson('/api/auth/login', [
            'email' => 'carlos-papeis@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        foreach ([$professionalAuth, $customerAuth] as $token) {
            $this->actingWithToken($token)->postJson('/api/services', [
                'name' => 'Corte masculino',
                'duration_minutes' => 30,
            ])->assertForbidden();

            $this->actingWithToken($token)->postJson('/api/professionals', [
                'name' => 'Outro Profissional',
            ])->assertForbidden();

            $this->actingWithToken($token)->postJson('/api/subscription-plans', [
                'name' => 'Plano Teste',
                'price_cents' => 1000,
            ])->assertForbidden();
        }

        // Cliente tambem nao pode acessar rotas restritas a staff (owner+professional).
        $this->actingWithToken($customerAuth)->getJson('/api/clients')->assertForbidden();
    }

    public function test_booking_blocked_when_subscription_is_overdue(): void
    {
        [$token, $client, $professional, $service] = $this->baseCatalog('Salao Inadimplencia', 'owner-inadimplencia@example.com');

        $plan = $this->actingWithToken($token)->postJson('/api/subscription-plans', [
            'name' => 'Plano Simples',
            'price_cents' => 9990,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $subscription = $this->actingWithToken($token)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'overdue',
        ])->assertCreated()->json('id');

        $this->actingWithToken($token)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertUnprocessable();
    }

    public function test_booking_blocked_when_usage_limit_reached(): void
    {
        [$token, $client, $professional, $service] = $this->baseCatalog('Salao Limite Uso', 'owner-limite@example.com');

        $plan = $this->actingWithToken($token)->postJson('/api/subscription-plans', [
            'name' => 'Plano Limitado',
            'price_cents' => 9990,
            'usage_limit' => 1,
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $subscription = $this->actingWithToken($token)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        $firstAppointment = $this->actingWithToken($token)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertCreated()->json('id');

        // Consome o unico uso permitido no mes.
        $this->actingWithToken($token)->postJson("/api/appointments/{$firstAppointment}/complete")->assertOk();

        $this->actingWithToken($token)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDays(2)->setTime(10, 0),
        ])->assertUnprocessable();
    }

    public function test_booking_blocked_outside_allowed_weekday(): void
    {
        [$token, $client, $professional, $service] = $this->baseCatalog('Salao Dia Permitido', 'owner-dia@example.com');

        $monday = now()->next(\Carbon\Carbon::MONDAY)->setTime(10, 0);
        $tuesday = now()->next(\Carbon\Carbon::TUESDAY)->setTime(10, 0);

        $plan = $this->actingWithToken($token)->postJson('/api/subscription-plans', [
            'name' => 'Plano Segunda',
            'price_cents' => 9990,
            'allowed_weekdays' => [1],
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $subscription = $this->actingWithToken($token)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        $this->actingWithToken($token)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => $tuesday,
        ])->assertUnprocessable();

        $this->actingWithToken($token)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => $monday,
        ])->assertCreated();
    }

    public function test_booking_blocked_outside_allowed_time_window(): void
    {
        [$token, $client, $professional, $service] = $this->baseCatalog('Salao Horario Permitido', 'owner-horario@example.com');

        $plan = $this->actingWithToken($token)->postJson('/api/subscription-plans', [
            'name' => 'Plano Horario',
            'price_cents' => 9990,
            'allowed_start_time' => '08:00',
            'allowed_end_time' => '12:00',
            'services' => [['id' => $service]],
        ])->assertCreated()->json('id');

        $subscription = $this->actingWithToken($token)->postJson('/api/client-subscriptions', [
            'client_id' => $client,
            'subscription_plan_id' => $plan,
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ])->assertCreated()->json('id');

        $this->actingWithToken($token)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professional,
            'service_id' => $service,
            'client_subscription_id' => $subscription,
            'starts_at' => now()->addDay()->setTime(15, 0),
        ])->assertUnprocessable();
    }

    public function test_customer_can_only_book_appointment_for_themselves(): void
    {
        [$token, , $professional, $service] = $this->baseCatalog('Salao Reserva Propria', 'owner-reserva@example.com');

        $ownClient = $this->actingWithToken($token)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880002',
            'email' => 'carlos-reserva@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $otherClient = $this->actingWithToken($token)->postJson('/api/clients', [
            'name' => 'Joao Ribeiro',
            'phone' => '11988880003',
        ])->assertCreated()->json('id');

        $customerToken = $this->postJson('/api/auth/login', [
            'email' => 'carlos-reserva@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $appointment = $this->actingWithToken($customerToken)->postJson('/api/appointments', [
            'client_id' => $otherClient,
            'professional_id' => $professional,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertCreated();

        $appointment->assertJsonPath('client_id', $ownClient);
    }

    public function test_professional_can_only_complete_own_appointments(): void
    {
        $ownerToken = $this->ownerToken('Salao Conclusao', 'owner-conclusao@example.com');

        $service = $this->actingWithToken($ownerToken)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($ownerToken)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988880004',
        ])->assertCreated()->json('id');

        $professionalA = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
            'email' => 'ana-conclusao@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Rafael Souza',
            'email' => 'rafael-conclusao@example.com',
            'password' => 'senhaforte1',
        ])->assertCreated();

        $appointment = $this->actingWithToken($ownerToken)->postJson('/api/appointments', [
            'client_id' => $client,
            'professional_id' => $professionalA,
            'service_id' => $service,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ])->assertCreated()->json('id');

        $otherProfessionalToken = $this->postJson('/api/auth/login', [
            'email' => 'rafael-conclusao@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $this->actingWithToken($otherProfessionalToken)
            ->postJson("/api/appointments/{$appointment}/complete")
            ->assertForbidden();

        $ownProfessionalToken = $this->postJson('/api/auth/login', [
            'email' => 'ana-conclusao@example.com',
            'password' => 'senhaforte1',
        ])->json('token');

        $this->actingWithToken($ownProfessionalToken)
            ->postJson("/api/appointments/{$appointment}/complete")
            ->assertOk();
    }

    /**
     * Cria proprietario, servico, profissional e cliente basicos para os testes
     * de restricao de agendamento, evitando repetir o mesmo setup em cada teste.
     *
     * @return array{0: string, 1: int, 2: int, 3: int} Token do owner, id do cliente, id do profissional, id do servico.
     */
    private function baseCatalog(string $tenantName, string $email): array
    {
        $token = $this->ownerToken($tenantName, $email);

        $service = $this->actingWithToken($token)->postJson('/api/services', [
            'name' => 'Corte masculino',
            'duration_minutes' => 30,
        ])->assertCreated()->json('id');

        $client = $this->actingWithToken($token)->postJson('/api/clients', [
            'name' => 'Carlos Mendes',
            'phone' => '11988889999',
        ])->assertCreated()->json('id');

        $professional = $this->actingWithToken($token)->postJson('/api/professionals', [
            'name' => 'Ana Souza',
        ])->assertCreated()->json('id');

        return [$token, $client, $professional, $service];
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
