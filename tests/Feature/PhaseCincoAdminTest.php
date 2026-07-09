<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseCincoAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_works_with_null_tenant_id(): void
    {
        $adminToken = $this->adminToken('admin-login@example.com');

        $me = $this->actingWithToken($adminToken)->getJson('/api/me')->assertOk();

        $me->assertJsonPath('role', 'admin');
        $me->assertJsonPath('tenant_id', null);
        $me->assertJsonPath('tenant', null);
    }

    public function test_non_admin_roles_cannot_access_admin_routes(): void
    {
        $ownerToken = $this->ownerToken('Salao Admin Roles', 'owner-admin-roles@example.com');
        [$professionalToken, ] = $this->professionalWithLogin($ownerToken, 'prof-admin-roles@example.com');
        $customerToken = $this->customerToken($ownerToken, 'cliente-admin-roles@example.com');

        $tenant = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        foreach ([$ownerToken, $professionalToken, $customerToken] as $token) {
            $this->actingWithToken($token)->getJson('/api/admin/dashboard')->assertForbidden();
            $this->actingWithToken($token)->getJson('/api/admin/tenants')->assertForbidden();
            $this->actingWithToken($token)->patchJson("/api/admin/tenants/{$tenant}/founder", ['is_founder' => true])->assertForbidden();
            $this->actingWithToken($token)->postJson("/api/admin/tenants/{$tenant}/subscription/extend", ['months' => 12])->assertForbidden();
        }
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->getJson('/api/admin/tenants')->assertUnauthorized();
        $this->patchJson('/api/admin/tenants/1/founder', ['is_founder' => true])->assertUnauthorized();
        $this->postJson('/api/admin/tenants/1/subscription/extend', ['months' => 12])->assertUnauthorized();
    }

    public function test_admin_lists_all_tenants_across_multiple_owners(): void
    {
        $this->ownerToken('Salao Um', 'owner-lista-um@example.com');
        $this->ownerToken('Salao Dois', 'owner-lista-dois@example.com');
        $adminToken = $this->adminToken('admin-lista@example.com');

        $tenants = $this->actingWithToken($adminToken)->getJson('/api/admin/tenants')->assertOk();

        $names = collect($tenants->json())->pluck('name');
        $this->assertTrue($names->contains('Salao Um'));
        $this->assertTrue($names->contains('Salao Dois'));
        $tenants->assertJsonPath('0.is_founder', false);
    }

    public function test_admin_toggles_founder_flag_on_and_off(): void
    {
        $ownerToken = $this->ownerToken('Salao Fundador', 'owner-fundador@example.com');
        $adminToken = $this->adminToken('admin-fundador@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        $this->actingWithToken($adminToken)->patchJson("/api/admin/tenants/{$tenantId}/founder", [
            'is_founder' => true,
        ])->assertOk()->assertJsonPath('is_founder', true);

        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'is_founder' => true]);

        $this->actingWithToken($adminToken)->patchJson("/api/admin/tenants/{$tenantId}/founder", [
            'is_founder' => false,
        ])->assertOk()->assertJsonPath('is_founder', false);

        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'is_founder' => false]);
    }

    public function test_owner_can_see_own_founder_flag_via_get_tenant(): void
    {
        $ownerToken = $this->ownerToken('Salao Ve Selo', 'owner-ve-selo@example.com');
        $adminToken = $this->adminToken('admin-ve-selo@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        $this->actingWithToken($adminToken)->patchJson("/api/admin/tenants/{$tenantId}/founder", [
            'is_founder' => true,
        ])->assertOk();

        $this->actingWithToken($ownerToken)->getJson('/api/tenant')
            ->assertOk()
            ->assertJsonPath('is_founder', true);
    }

    public function test_admin_extends_subscription_for_free_and_does_not_charge(): void
    {
        $ownerToken = $this->ownerToken('Salao Cortesia', 'owner-cortesia@example.com');
        $adminToken = $this->adminToken('admin-cortesia@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        $response = $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'plan_code' => 'premium',
            'months' => 12,
            'reason' => 'Negociacao fundador',
        ])->assertOk();

        $response->assertJsonPath('saas_subscription.status', 'active');
        $response->assertJsonPath('saas_subscription.price_cents', 0);
        $this->assertStringContainsString('cortesia', $response->json('saas_subscription.plan_name'));

        $endsAt = \Carbon\Carbon::parse($response->json('saas_subscription.current_period_ends_at'));
        $this->assertTrue($endsAt->between(now()->addMonths(11), now()->addMonths(13)));
    }

    public function test_admin_extend_subscription_stacks_on_top_of_existing_future_expiry(): void
    {
        $ownerToken = $this->ownerToken('Salao Empilha', 'owner-empilha@example.com');
        $adminToken = $this->adminToken('admin-empilha@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        // Primeiro concede 2 meses, depois mais 12 — deve empilhar (~14), nao resetar pra 12.
        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'months' => 2,
        ])->assertOk();

        $response = $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'months' => 12,
        ])->assertOk();

        $endsAt = \Carbon\Carbon::parse($response->json('saas_subscription.current_period_ends_at'));
        $this->assertTrue($endsAt->between(now()->addMonths(13), now()->addMonths(15)));
    }

    public function test_admin_extend_subscription_defaults_to_premium_when_plan_code_omitted(): void
    {
        $ownerToken = $this->ownerToken('Salao Default Premium', 'owner-default-premium@example.com');
        $adminToken = $this->adminToken('admin-default-premium@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        $response = $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'months' => 6,
        ])->assertOk();

        $this->assertStringContainsString('Premium', $response->json('saas_subscription.plan_name'));
    }

    public function test_admin_extend_subscription_validates_months_and_plan_code(): void
    {
        $ownerToken = $this->ownerToken('Salao Validacao', 'owner-validacao@example.com');
        $adminToken = $this->adminToken('admin-validacao@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'months' => 0,
        ])->assertStatus(422);

        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [])
            ->assertStatus(422);

        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'months' => 12,
            'plan_code' => 'nao-existe',
        ])->assertStatus(422);
    }

    public function test_admin_extend_subscription_applies_downgrade_plan_gate_limits(): void
    {
        $ownerToken = $this->ownerToken('Salao Downgrade Admin', 'owner-downgrade-admin@example.com');
        $adminToken = $this->adminToken('admin-downgrade@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');

        // Trial (Premium) libera ate 3 profissionais; cadastra 3 ativos.
        $professionalIds = [];
        foreach (range(1, 3) as $i) {
            $professionalIds[] = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
                'name' => "Profissional {$i}",
            ])->assertCreated()->json('id');
        }

        // Admin concede o tier Basico (max_professionals = 3, entao ainda cabe) — troca pra Basico de verdade primeiro.
        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'plan_code' => 'basico',
            'months' => 1,
        ])->assertOk();

        // Basico tambem libera 3 — nenhum deveria ser desativado.
        foreach ($professionalIds as $id) {
            $this->assertDatabaseHas('professionals', ['id' => $id, 'is_active' => true]);
        }
    }

    public function test_admin_extend_subscription_records_audit_grant(): void
    {
        $ownerToken = $this->ownerToken('Salao Auditoria', 'owner-auditoria@example.com');
        $adminToken = $this->adminToken('admin-auditoria@example.com');
        $tenantId = $this->actingWithToken($ownerToken)->getJson('/api/tenant')->json('id');
        $adminId = User::where('email', 'admin-auditoria@example.com')->firstOrFail()->id;

        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantId}/subscription/extend", [
            'plan_code' => 'premium',
            'months' => 12,
            'reason' => 'Cortesia parceria',
        ])->assertOk();

        $this->assertDatabaseHas('admin_subscription_grants', [
            'tenant_id' => $tenantId,
            'admin_user_id' => $adminId,
            'months_added' => 12,
            'reason' => 'Cortesia parceria',
        ]);
    }

    public function test_admin_dashboard_summary_counts_tenants_by_status(): void
    {
        $adminToken = $this->adminToken('admin-dashboard@example.com');

        // 1 trial (padrao do onboarding).
        $this->ownerToken('Salao Trial Dashboard', 'owner-trial-dashboard@example.com');

        // 1 ativo pago.
        $ownerAtivoToken = $this->ownerToken('Salao Ativo Dashboard', 'owner-ativo-dashboard@example.com');
        $this->actingWithToken($ownerAtivoToken)->patchJson('/api/saas-subscription', [
            'plan_code' => 'basico',
        ])->assertOk();

        // 1 trial vencido.
        $ownerVencidoToken = $this->ownerToken('Salao Vencido Dashboard', 'owner-vencido-dashboard@example.com');
        $tenantVencidoId = $this->actingWithToken($ownerVencidoToken)->getJson('/api/tenant')->json('id');
        \App\Models\SaasSubscription::where('tenant_id', $tenantVencidoId)->update([
            'trial_ends_at' => now()->subDay(),
        ]);

        $summary = $this->actingWithToken($adminToken)->getJson('/api/admin/dashboard')->assertOk();

        $this->assertSame(3, $summary->json('total_tenants'));
        $this->assertSame(1, $summary->json('active_tenants'));
        $this->assertGreaterThanOrEqual(1, $summary->json('trial_tenants'));
        $this->assertGreaterThanOrEqual(1, $summary->json('expired_tenants'));
    }

    public function test_admin_dashboard_projected_revenue_excludes_free_founder_grants(): void
    {
        $adminToken = $this->adminToken('admin-receita@example.com');

        $ownerPagoToken = $this->ownerToken('Salao Pago Receita', 'owner-pago-receita@example.com');
        $this->actingWithToken($ownerPagoToken)->patchJson('/api/saas-subscription', [
            'plan_code' => 'intermediario',
        ])->assertOk();

        $ownerCortesiaToken = $this->ownerToken('Salao Cortesia Receita', 'owner-cortesia-receita@example.com');
        $tenantCortesiaId = $this->actingWithToken($ownerCortesiaToken)->getJson('/api/tenant')->json('id');
        $this->actingWithToken($adminToken)->postJson("/api/admin/tenants/{$tenantCortesiaId}/subscription/extend", [
            'plan_code' => 'premium',
            'months' => 12,
        ])->assertOk();

        $summary = $this->actingWithToken($adminToken)->getJson('/api/admin/dashboard')->assertOk();

        $this->assertSame(12999, $summary->json('projected_revenue_cents'));
    }

    public function test_admin_dashboard_total_users_excludes_admin_accounts(): void
    {
        $ownerToken = $this->ownerToken('Salao Usuarios', 'owner-usuarios@example.com');
        $this->professionalWithLogin($ownerToken, 'prof-usuarios@example.com');
        $this->customerToken($ownerToken, 'cliente-usuarios@example.com');
        $adminToken = $this->adminToken('admin-usuarios@example.com');
        $this->adminToken('admin-usuarios-2@example.com');

        $summary = $this->actingWithToken($adminToken)->getJson('/api/admin/dashboard')->assertOk();

        // 1 dono + 1 profissional + 1 cliente = 3 (os 2 admins ficam de fora).
        $this->assertSame(3, $summary->json('total_users'));
    }

    public function test_extend_subscription_returns_404_for_unknown_tenant(): void
    {
        $adminToken = $this->adminToken('admin-404@example.com');

        $this->actingWithToken($adminToken)->postJson('/api/admin/tenants/999999/subscription/extend', [
            'months' => 12,
        ])->assertNotFound();

        $this->actingWithToken($adminToken)->patchJson('/api/admin/tenants/999999/founder', [
            'is_founder' => true,
        ])->assertNotFound();
    }

    private function adminToken(string $email): string
    {
        User::create([
            'name' => 'Admin Teste',
            'email' => $email,
            'role' => 'admin',
            'tenant_id' => null,
            'password' => 'password123',
        ]);

        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk()->json('token');
    }

    private function professionalWithLogin(string $ownerToken, string $email): array
    {
        $professionalId = $this->actingWithToken($ownerToken)->postJson('/api/professionals', [
            'name' => 'Profissional Teste',
            'email' => $email,
            'password' => 'senhaforte1',
        ])->assertCreated()->json('id');

        $professionalToken = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'senhaforte1',
        ])->assertOk()->json('token');

        return [$professionalToken, $professionalId];
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
        ])->assertOk()->json('token');
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
