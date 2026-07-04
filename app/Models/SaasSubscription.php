<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'saas_plan_id',
        'plan_name',
        'price_cents',
        'status',
        'trial_ends_at',
        'current_period_ends_at',
    ];

    protected $appends = [
        'effective_status',
        'trial_days_remaining',
        'limits',
        'usage',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    /**
     * Trial vencido sem o dono ter escolhido um tier pago ainda. Calculado a
     * cada leitura (sem job agendado) para nao depender de infraestrutura de
     * cron so pra isso.
     */
    public function isTrialExpired(): bool
    {
        return $this->status === 'trial' && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /**
     * Status "trial_expired" e derivado, nunca gravado no banco: assim que o
     * dono escolhe um plano pago, `status` vira `active` e este calculo some.
     */
    protected function effectiveStatus(): Attribute
    {
        return Attribute::get(fn () => $this->isTrialExpired() ? 'trial_expired' : $this->status);
    }

    protected function trialDaysRemaining(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status !== 'trial' || $this->trial_ends_at === null) {
                return null;
            }

            return max(0, now()->startOfDay()->diffInDays($this->trial_ends_at->copy()->startOfDay(), false));
        });
    }

    protected function limits(): Attribute
    {
        return Attribute::get(fn () => [
            'professionals' => $this->plan?->max_professionals,
            'client_subscriptions' => $this->plan?->max_client_subscriptions,
            'units' => $this->plan?->max_units,
        ]);
    }

    protected function usage(): Attribute
    {
        return Attribute::get(fn () => [
            'professionals' => Professional::where('tenant_id', $this->tenant_id)->where('is_active', true)->count(),
            'client_subscriptions' => ClientSubscription::where('tenant_id', $this->tenant_id)->where('status', 'active')->count(),
            'units' => $this->tenant?->units_count ?? 1,
        ]);
    }
}
