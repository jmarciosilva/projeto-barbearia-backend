<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalAdvance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ProfessionalFinanceController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function me(Request $request)
    {
        abort_unless($request->user()->role === 'professional', 403, 'Somente profissionais possuem extrato proprio.');

        $professional = Professional::where('tenant_id', $this->tenantId($request))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->statement($request, $professional);
    }

    public function show(Request $request, Professional $professional)
    {
        abort_if($professional->tenant_id !== $this->tenantId($request), 404);

        return $this->statement($request, $professional);
    }

    public function advances(Request $request, Professional $professional)
    {
        abort_if($professional->tenant_id !== $this->tenantId($request), 404);

        return ProfessionalAdvance::where('tenant_id', $this->tenantId($request))
            ->where('professional_id', $professional->id)
            ->latest('paid_at')
            ->get();
    }

    public function storeAdvance(Request $request, Professional $professional)
    {
        abort_if($professional->tenant_id !== $this->tenantId($request), 404);

        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $advance = $this->transaction(fn () => ProfessionalAdvance::create([
            'tenant_id' => $this->tenantId($request),
            'professional_id' => $professional->id,
            'amount_cents' => $data['amount_cents'],
            'paid_at' => $data['paid_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
        ]));

        return response()->json($advance, 201);
    }

    private function statement(Request $request, Professional $professional): array
    {
        $period = $request->query('period', 'month');
        $now = CarbonImmutable::now();
        $from = $period === 'week' ? $now->startOfWeek() : $now->startOfMonth();
        $to = $period === 'week' ? $now->endOfWeek() : $now->endOfMonth();

        $appointments = Appointment::where('tenant_id', $this->tenantId($request))
            ->where('professional_id', $professional->id)
            ->where('status', 'completed')
            ->whereBetween('starts_at', [$from, $to])
            ->with(['service', 'client'])
            ->orderBy('starts_at')
            ->get();

        $grossCents = (int) $appointments->sum(fn (Appointment $appointment) => $appointment->service?->price_cents ?? 0);
        $commissionPercentage = $professional->commission_percentage ?? 0;
        $commissionCents = (int) round($grossCents * ($commissionPercentage / 100));

        // Separa avulso (sem assinatura) de plano (com assinatura), tanto em
        // quantidade quanto em valor, para o profissional entender de onde
        // vem o proprio volume/receita do mes.
        $avulsoAppointments = $appointments->whereNull('client_subscription_id');
        $planoAppointments = $appointments->whereNotNull('client_subscription_id');
        $avulsoRevenueCents = (int) $avulsoAppointments->sum(fn (Appointment $appointment) => $appointment->service?->price_cents ?? 0);
        $planoRevenueCents = (int) $planoAppointments->sum(fn (Appointment $appointment) => $appointment->service?->price_cents ?? 0);

        $advances = ProfessionalAdvance::where('tenant_id', $this->tenantId($request))
            ->where('professional_id', $professional->id)
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at')
            ->get();

        $advancesCents = (int) $advances->sum('amount_cents');
        $tenant = $request->user()->tenant;

        return [
            'professional_id' => $professional->id,
            'professional_name' => $professional->name,
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'payment_day' => $tenant?->professional_payment_day ?? 5,
            'completed_count' => $appointments->count(),
            'avulso_count' => $avulsoAppointments->count(),
            'plano_count' => $planoAppointments->count(),
            'gross_cents' => $grossCents,
            'avulso_revenue_cents' => $avulsoRevenueCents,
            'plano_revenue_cents' => $planoRevenueCents,
            'commission_percentage' => $commissionPercentage,
            'commission_cents' => $commissionCents,
            'advances_cents' => $advancesCents,
            'net_cents' => max(0, $commissionCents - $advancesCents),
            'appointments' => $appointments,
            'advances' => $advances,
        ];
    }
}
