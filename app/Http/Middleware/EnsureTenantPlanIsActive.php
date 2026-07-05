<?php

namespace App\Http\Middleware;

use App\Models\SaasSubscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueio gracioso de fim de trial (spec 3.1): leitura continua liberada
 * (nada e escondido/apagado), mas qualquer escrita para quando o trial vence
 * sem o dono escolher um plano pago. A troca de plano, o logout e a troca
 * da propria credencial continuam liberados, senao o dono ficaria trancado
 * sem saida (inclusive sem conseguir corrigir a propria senha).
 */
class EnsureTenantPlanIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($request->is('api/auth/logout') || $request->is('api/saas-subscription') || $request->is('api/me/credentials')) {
            return $next($request);
        }

        $tenantId = $request->user()?->tenant_id;

        if ($tenantId) {
            $subscription = SaasSubscription::where('tenant_id', $tenantId)->first();

            abort_if(
                $subscription?->isTrialExpired() === true,
                402,
                'Seu periodo de teste expirou. Escolha um plano para continuar usando o Clube do Salao.'
            );
        }

        return $next($request);
    }
}
