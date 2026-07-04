<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\SaasPlan;
use App\Models\SaasSubscription;
use App\Models\Tenant;
use App\Support\PlanGate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaasSubscriptionController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    /**
     * Tiers pagos disponiveis para contratacao (trial nao e selecionavel:
     * todo tenant novo entra nele automaticamente no onboarding).
     */
    public function plans()
    {
        return SaasPlan::where('code', '!=', 'trial')->orderBy('price_cents')->get();
    }

    /**
     * Proprietario seleciona/troca de tier pago. Efetiva na hora (sem
     * cobranca real ainda — Asaas fica pra Fase 2); aplica a regra de
     * downgrade (spec 3.5) quando o uso atual excede o novo limite.
     */
    public function update(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'plan_code' => ['required', 'string', Rule::in(['basico', 'intermediario', 'premium'])],
        ]);

        $plan = SaasPlan::where('code', $data['plan_code'])->firstOrFail();
        $subscription = SaasSubscription::where('tenant_id', $tenantId)->firstOrFail();

        $this->transaction(function () use ($subscription, $plan, $tenantId) {
            $subscription->update([
                'saas_plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'status' => 'active',
                'current_period_ends_at' => now()->addMonth(),
            ]);

            PlanGate::for($tenantId)->applyLimits($plan);
        });

        return Tenant::with('saasSubscription.plan')->findOrFail($tenantId);
    }
}
