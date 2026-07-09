<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasSubscription;
use App\Models\Tenant;
use App\Models\User;

/**
 * Painel do administrador da plataforma (roadmap Fase 5): visao geral de
 * saloes cadastrados/ativos/inadimplentes e receita projetada. Nenhuma
 * query e tenant-scoped aqui — e o oposto de todo outro controller do
 * sistema, por isso nao usa UsesTenant.
 */
class AdminDashboardController extends Controller
{
    public function summary()
    {
        $subscriptions = SaasSubscription::all();

        $activeTenants = $subscriptions->where('status', 'active')->count();
        $trialTenants = $subscriptions->filter(fn (SaasSubscription $s) => $s->effective_status === 'trial')->count();
        // "Inadimplente" aqui so captura trial vencido sem plano escolhido.
        // Uma assinatura active com current_period_ends_at no passado nao
        // cai em lugar nenhum, porque nao existe enforcement de vencimento
        // pra planos ativos ainda (mesma lacuna ja aceita em
        // EnsureTenantPlanIsActive) — fora de escopo resolver aqui.
        $expiredTenants = $subscriptions->filter(fn (SaasSubscription $s) => $s->effective_status === 'trial_expired')->count();

        // Cortesias (AdminTenantController::extendSubscription) zeram
        // price_cents de proposito, entao ja ficam fora dessa soma sem
        // filtro adicional.
        $projectedRevenueCents = (int) $subscriptions->where('status', 'active')->sum('price_cents');

        return [
            'total_tenants' => Tenant::count(),
            'active_tenants' => $activeTenants,
            'trial_tenants' => $trialTenants,
            'expired_tenants' => $expiredTenants,
            'founder_tenants' => Tenant::where('is_founder', true)->count(),
            'projected_revenue_cents' => $projectedRevenueCents,
            // Administrador nao e "usuario do produto" pra fins de metrica
            // de negocio, so quem opera a plataforma.
            'total_users' => User::where('role', '!=', 'admin')->count(),
        ];
    }
}
