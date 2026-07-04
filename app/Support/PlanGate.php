<?php

namespace App\Support;

use App\Models\ClientSubscription;
use App\Models\Professional;
use App\Models\SaasPlan;
use App\Models\SaasSubscription;
use Illuminate\Support\Collection;

/**
 * Ponto central de decisao sobre o que um tenant pode fazer de acordo com o
 * tier do SaaS contratado (spec, secao 3). Mantem `plan_features`/limites
 * fora dos controllers para as duas pontas (backend e app) sempre lerem a
 * mesma fonte de verdade.
 */
class PlanGate
{
    private function __construct(private readonly int $tenantId) {}

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    public function subscription(): ?SaasSubscription
    {
        return SaasSubscription::where('tenant_id', $this->tenantId)->first();
    }

    public function currentPlan(): ?SaasPlan
    {
        return $this->subscription()?->plan;
    }

    public function canAddProfessional(): bool
    {
        $max = $this->currentPlan()?->max_professionals;

        return $max === null || $this->activeProfessionalsCount() < $max;
    }

    public function canAddClientSubscription(): bool
    {
        $max = $this->currentPlan()?->max_client_subscriptions;

        return $max === null || $this->activeClientSubscriptionsCount() < $max;
    }

    /**
     * Regra de downgrade (spec 3.5): nunca bloquear a troca de plano. Os
     * registros mais antigos permanecem ativos dentro do novo limite; os
     * excedentes viram inativos (nunca removidos) ate o dono decidir.
     */
    public function applyLimits(SaasPlan $plan): void
    {
        $this->deactivateExcess(
            $this->activeProfessionalIdsOrdered(),
            $plan->max_professionals,
            fn (Collection $ids) => Professional::whereIn('id', $ids)->update(['is_active' => false]),
        );

        $this->deactivateExcess(
            $this->activeClientSubscriptionIdsOrdered(),
            $plan->max_client_subscriptions,
            fn (Collection $ids) => ClientSubscription::whereIn('id', $ids)->update(['status' => 'paused']),
        );
    }

    private function activeProfessionalsCount(): int
    {
        return Professional::where('tenant_id', $this->tenantId)->where('is_active', true)->count();
    }

    private function activeClientSubscriptionsCount(): int
    {
        return ClientSubscription::where('tenant_id', $this->tenantId)->where('status', 'active')->count();
    }

    private function activeProfessionalIdsOrdered(): Collection
    {
        return Professional::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->pluck('id');
    }

    private function activeClientSubscriptionIdsOrdered(): Collection
    {
        return ClientSubscription::where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->pluck('id');
    }

    private function deactivateExcess(Collection $orderedIds, ?int $max, callable $deactivate): void
    {
        if ($max === null) {
            return;
        }

        $excessIds = $orderedIds->slice($max)->values();

        if ($excessIds->isNotEmpty()) {
            $deactivate($excessIds);
        }
    }
}
