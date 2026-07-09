<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Controller;
use App\Models\AdminSubscriptionGrant;
use App\Models\SaasPlan;
use App\Models\SaasSubscription;
use App\Models\Tenant;
use App\Support\PlanGate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Gestao de estabelecimentos pelo administrador da plataforma (roadmap Fase
 * 5): selo de "Salao Fundador" e cortesia de assinatura. Nunca usa
 * UsesTenant — e cross-tenant por definicao, o admin nao pertence a nenhum
 * estabelecimento.
 */
class AdminTenantController extends Controller
{
    use RunsDatabaseTransactions;

    public function index()
    {
        return Tenant::with('saasSubscription.plan')->orderBy('name')->get();
    }

    public function toggleFounder(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'is_founder' => ['required', 'boolean'],
        ]);

        $this->transaction(fn () => $tenant->update(['is_founder' => $data['is_founder']]));

        return $tenant->fresh('saasSubscription.plan');
    }

    /**
     * Concede tempo de assinatura sem cobranca (ex: negociacao com salao
     * fundador que trouxe mais saloes pro app). `price_cents` fica zerado de
     * proposito: e o que faz a receita projetada do painel administrativo
     * (AdminDashboardController::summary) ja vir correta sem filtro extra —
     * uma cortesia simplesmente soma zero. O valor real do plano no momento
     * da concessao fica registrado em `admin_subscription_grants`, pra nao
     * perder "quanto isso custaria" numa negociacao futura.
     */
    public function extendSubscription(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan_code' => ['nullable', 'string', Rule::in(['basico', 'intermediario', 'premium'])],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = SaasPlan::where('code', $data['plan_code'] ?? 'premium')->firstOrFail();
        $subscription = SaasSubscription::where('tenant_id', $tenant->id)->firstOrFail();

        $this->transaction(function () use ($request, $tenant, $plan, $subscription, $data) {
            $previousEndsAt = $subscription->current_period_ends_at;

            // Estende a partir da data que for maior entre "agora" e o
            // vencimento atual, pra nao resetar pra tras quando o tenant ja
            // tem prazo futuro (ex: fundador que ja tinha 2 meses ganha mais
            // 12, fica com 14, nao so 12).
            $extendsFrom = $previousEndsAt?->isFuture() ? $previousEndsAt : Carbon::now();
            $newEndsAt = $extendsFrom->copy()->addMonths($data['months']);

            $subscription->update([
                'saas_plan_id' => $plan->id,
                'plan_name' => "{$plan->name} (cortesia)",
                'price_cents' => 0,
                'status' => 'active',
                'current_period_ends_at' => $newEndsAt,
            ]);

            PlanGate::for($tenant->id)->applyLimits($plan);

            AdminSubscriptionGrant::create([
                'tenant_id' => $tenant->id,
                'admin_user_id' => $request->user()->id,
                'saas_plan_id' => $plan->id,
                'months_added' => $data['months'],
                'previous_current_period_ends_at' => $previousEndsAt,
                'new_current_period_ends_at' => $newEndsAt,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        return $tenant->fresh('saasSubscription.plan');
    }
}
