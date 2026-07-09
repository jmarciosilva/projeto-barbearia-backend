<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Professional;
use App\Models\ProfessionalAdvance;
use App\Models\ProfessionalScheduleOverride;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Painel Inteligente do Proprietario (roadmap Fase 4): resumo do dia,
 * indice de ocupacao da equipe e inteligencia de retorno de clientes.
 * Tudo somente leitura, exclusivo do dono.
 */
class OwnerDashboardController extends Controller
{
    use UsesTenant;

    public function summary(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $today = CarbonImmutable::now()->startOfDay();
        $tomorrow = $today->addDay();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();

        $appointmentsToday = Appointment::where('tenant_id', $tenantId)
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->with(['service', 'payment'])
            ->get();

        $canceledToday = $appointmentsToday->whereIn('status', ['canceled', 'no_show']);
        $activeToday = $appointmentsToday->whereNotIn('status', ['canceled', 'no_show']);
        // Pendente = agendamento de hoje com o pagamento avulso ainda nao confirmado pelo dono.
        $pendingToday = $activeToday->filter(fn (Appointment $appointment) => $appointment->payment?->status === 'pending');

        $waitlistCount = WaitlistEntry::where('tenant_id', $tenantId)
            ->where('status', 'waiting')
            ->count();

        $expectedRevenueTodayCents = (int) $activeToday->sum(
            fn (Appointment $appointment) => $appointment->service?->price_cents ?? 0
        );

        $recurringRevenueMonthCents = (int) ClientSubscription::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('plan')
            ->get()
            ->sum(fn (ClientSubscription $subscription) => $subscription->plan?->price_cents ?? 0);

        // Receita do mes (avulso): conta o que de fato entrou no caixa este
        // mes, nao so o que ja foi totalmente quitado. Pagamento confirmado
        // na hora (markPaid, sem recibo) conta o valor cheio no mes do
        // paid_at; pagamento recebido aos poucos (fiado com recebimentos
        // parciais, ver PaymentController::receive) tem cada recibo somado
        // no mes em que foi de fato recebido — senao um recebimento parcial
        // ficava invisivel na receita do mes ate o fiado ser quitado por
        // completo, e nesse ponto contava tudo de uma vez no mes errado.
        // `doesntHave('receipts')` evita contar duas vezes o pagamento que
        // terminou de ser quitado via recibo (ja somado abaixo).
        $walkinRevenueMonthCents = (int) Payment::where('tenant_id', $tenantId)
            ->where('status', 'paid')
            ->whereNull('client_subscription_id')
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->doesntHave('receipts')
            ->sum('amount_cents');

        $walkinRevenueMonthCents += (int) PaymentReceipt::where('tenant_id', $tenantId)
            ->whereBetween('received_at', [$monthStart, $monthEnd])
            ->whereHas('payment', fn ($query) => $query->whereNull('client_subscription_id'))
            ->sum('amount_cents');

        // Fiado = pagamento que o dono explicitamente marcou com esse metodo
        // (nao um avulso comum ainda aguardando a primeira confirmacao, que
        // tambem fica com status=pending mas method continua o default).
        $openDebtCents = (int) Payment::where('tenant_id', $tenantId)
            ->where('method', 'fiado')
            ->where('status', 'pending')
            ->with('receipts')
            ->get()
            ->sum('remaining_cents');

        return [
            'appointments_today' => $appointmentsToday->count(),
            'confirmed_today' => $activeToday->count() - $pendingToday->count(),
            'pending_today' => $pendingToday->count(),
            'canceled_today' => $canceledToday->count(),
            'waitlist_count' => $waitlistCount,
            'expected_revenue_today_cents' => $expectedRevenueTodayCents,
            'recurring_revenue_month_cents' => $recurringRevenueMonthCents,
            'walkin_revenue_month_cents' => $walkinRevenueMonthCents,
            'open_debt_cents' => $openDebtCents,
        ];
    }

    public function occupancy(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $weekStart = CarbonImmutable::now()->startOfWeek();
        $weekDates = collect(range(0, 6))->map(fn ($offset) => $weekStart->addDays($offset));

        $professionals = Professional::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with(['workingHours', 'scheduleOverrides' => function ($query) use ($weekStart) {
                $query->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()]);
            }])
            ->orderBy('name')
            ->get();

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('professional_id', $professionals->pluck('id'))
            ->whereBetween('starts_at', [$weekStart, $weekStart->addDays(6)->endOfDay()])
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->get()
            ->groupBy(fn (Appointment $appointment) => $appointment->professional_id.'-'.$appointment->starts_at->toDateString());

        return $professionals->map(function (Professional $professional) use ($appointments, $weekDates) {
            $days = $weekDates
                ->map(function (CarbonImmutable $date) use ($professional, $appointments) {
                    $override = $professional->scheduleOverrides->firstWhere(
                        fn (ProfessionalScheduleOverride $override) => $override->date->isSameDay($date)
                    );

                    // Ajuste "nao vou trabalhar" some do indice, mesmo que haja horario
                    // recorrente cadastrado para o dia da semana.
                    if ($override && $override->is_off) {
                        return null;
                    }

                    $startsAt = $override?->starts_at;
                    $endsAt = $override?->ends_at;

                    if (! $override) {
                        $workingHour = $professional->workingHours->firstWhere('weekday', $date->dayOfWeek);
                        if (! $workingHour) {
                            return null;
                        }
                        $startsAt = $workingHour->starts_at;
                        $endsAt = $workingHour->ends_at;
                    }

                    $availableMinutes = CarbonImmutable::parse($startsAt)->diffInMinutes(CarbonImmutable::parse($endsAt));

                    $dayAppointments = $appointments->get($professional->id.'-'.$date->toDateString(), collect());
                    $occupiedMinutes = (int) $dayAppointments->sum(
                        fn (Appointment $appointment) => $appointment->starts_at->diffInMinutes($appointment->ends_at)
                    );

                    $percentage = $availableMinutes > 0
                        ? (int) min(100, round($occupiedMinutes / $availableMinutes * 100))
                        : 0;

                    return [
                        'weekday' => $date->dayOfWeek,
                        'date' => $date->toDateString(),
                        'has_override' => (bool) $override,
                        'available_minutes' => $availableMinutes,
                        'occupied_minutes' => $occupiedMinutes,
                        'percentage' => $percentage,
                    ];
                })
                ->filter()
                ->values();

            return [
                'professional_id' => $professional->id,
                'professional_name' => $professional->name,
                'days' => $days,
            ];
        })->values();
    }

    /**
     * Desempenho da equipe no mes corrente (roadmap Fase 4): mesma
     * agregacao ja usada no extrato individual do profissional
     * (ProfessionalFinanceController::statement), so que para todos os
     * profissionais ativos numa unica resposta, ordenada por receita gerada
     * — o dono ve quem esta performando bem sem precisar abrir um por um.
     */
    public function teamPerformance(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $monthEnd = CarbonImmutable::now()->endOfMonth();

        $professionals = Professional::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('professional_id', $professionals->pluck('id'))
            ->where('status', 'completed')
            ->whereBetween('starts_at', [$monthStart, $monthEnd])
            ->with('service')
            ->get()
            ->groupBy('professional_id');

        // Mesmo recorte de adiantamentos do mes ja usado no extrato individual
        // (ProfessionalFinanceController::statement), pra "Comissoes
        // profissionais" mostrar o valor a receber sem abrir um por um.
        $advances = ProfessionalAdvance::where('tenant_id', $tenantId)
            ->whereIn('professional_id', $professionals->pluck('id'))
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->get()
            ->groupBy('professional_id');

        $result = $professionals->map(function (Professional $professional) use ($appointments, $advances) {
            $professionalAppointments = $appointments->get($professional->id, collect());
            $avulsoAppointments = $professionalAppointments->whereNull('client_subscription_id');
            $planoAppointments = $professionalAppointments->whereNotNull('client_subscription_id');
            $grossCents = (int) $professionalAppointments->sum(fn (Appointment $appointment) => $appointment->service?->price_cents ?? 0);
            $commissionPercentage = $professional->commission_percentage ?? 0;
            $commissionCents = (int) round($grossCents * ($commissionPercentage / 100));
            $advancesCents = (int) $advances->get($professional->id, collect())->sum('amount_cents');

            return [
                'professional_id' => $professional->id,
                'professional_name' => $professional->name,
                'completed_count' => $professionalAppointments->count(),
                'avulso_count' => $avulsoAppointments->count(),
                'plano_count' => $planoAppointments->count(),
                'gross_cents' => $grossCents,
                'commission_percentage' => $commissionPercentage,
                'commission_cents' => $commissionCents,
                'advances_cents' => $advancesCents,
                'net_cents' => max(0, $commissionCents - $advancesCents),
            ];
        });

        return $result->sortByDesc('gross_cents')->values();
    }

    public function returnRisk(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $today = CarbonImmutable::now()->startOfDay();

        $clients = Client::where('tenant_id', $tenantId)
            ->with(['appointments' => function ($query) {
                $query->where('status', 'completed')->orderBy('starts_at');
            }])
            ->get();

        $result = $clients->map(function (Client $client) use ($today) {
            $visits = $client->appointments->pluck('starts_at');

            if ($visits->count() < 2) {
                return null;
            }

            // Normaliza para inicio do dia antes de calcular a diferenca: sem isso, o
            // horario dentro de cada starts_at gera dias fracionados (ex: 37.44).
            $intervals = [];
            for ($i = 1; $i < $visits->count(); $i++) {
                $intervals[] = $visits[$i - 1]->startOfDay()->diffInDays($visits[$i]->startOfDay());
            }

            $avgIntervalDays = array_sum($intervals) / count($intervals);
            $lastVisit = $visits->last();
            $daysSinceLast = $lastVisit->startOfDay()->diffInDays($today);
            $ratio = $avgIntervalDays > 0 ? $daysSinceLast / $avgIntervalDays : 0;

            // Faixas calibradas pelo exemplo dado pelo dono (38 dias desde o ultimo
            // atendimento, media de 25 -> razao 1,52 deve cair como "alta").
            if ($ratio < 0.85) {
                $probability = 'baixa';
            } elseif ($ratio <= 1.6) {
                $probability = 'alta';
            } else {
                $probability = 'media';
            }

            return [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'last_visit_at' => $lastVisit->toDateString(),
                'avg_interval_days' => (int) round($avgIntervalDays),
                'days_since_last' => $daysSinceLast,
                'probability' => $probability,
            ];
        })->filter()->values();

        return $result->sortByDesc(fn ($entry) => $entry['days_since_last'] / max(1, $entry['avg_interval_days']))->values();
    }
}
