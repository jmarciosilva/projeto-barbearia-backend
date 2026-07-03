<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Professional;
use App\Models\Service;
use App\Models\SubscriptionUsage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        return Appointment::where('tenant_id', $tenantId)
            ->with(['client', 'professional', 'service', 'subscription.plan'])
            ->when($request->query('from'), fn ($query, $from) => $query->where('starts_at', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'client_subscription_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $client = Client::where('tenant_id', $tenantId)->findOrFail($data['client_id']);
        $professional = Professional::where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($data['professional_id']);
        $service = Service::where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($data['service_id']);
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = $startsAt->copy()->addMinutes($service->duration_minutes);

        // Se houver assinatura, validamos inadimplencia, vencimento, servico incluso e restricoes do plano.
        if (! empty($data['client_subscription_id'])) {
            $this->assertSubscriptionCanBook($tenantId, $client->id, (int) $data['client_subscription_id'], $service->id, $startsAt);
        }

        // Evita dois atendimentos simultaneos para o mesmo profissional.
        abort_if($this->hasConflict($tenantId, $professional->id, $startsAt, $endsAt), 422, 'Profissional ja possui agendamento neste horario.');

        $appointment = $this->transaction(fn () => Appointment::create($data + [
            'tenant_id' => $tenantId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'scheduled',
        ]));

        return response()->json($appointment->fresh(['client', 'professional', 'service', 'subscription.plan']), 201);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $tenantId = $this->tenantId($request);
        abort_if($appointment->tenant_id !== $tenantId, 404);

        $data = $request->validate([
            'starts_at' => ['sometimes', 'date'],
            'professional_id' => ['sometimes', 'integer'],
            'status' => ['nullable', Rule::in(['scheduled', 'canceled', 'completed', 'no_show'])],
            'cancellation_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if (isset($data['professional_id'])) {
            Professional::where('tenant_id', $tenantId)->findOrFail($data['professional_id']);
        }

        if (isset($data['starts_at']) || isset($data['professional_id'])) {
            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $appointment->starts_at;
            $endsAt = $startsAt->copy()->addMinutes($appointment->service->duration_minutes);
            $professionalId = $data['professional_id'] ?? $appointment->professional_id;

            // Remarcacao tambem passa pela mesma checagem de conflito do agendamento.
            abort_if($this->hasConflict($tenantId, $professionalId, $startsAt, $endsAt, $appointment->id), 422, 'Profissional ja possui agendamento neste horario.');

            $data['starts_at'] = $startsAt;
            $data['ends_at'] = $endsAt;
        }

        $this->transaction(fn () => $appointment->update($data));

        return $appointment->fresh(['client', 'professional', 'service', 'subscription.plan']);
    }

    public function complete(Request $request, Appointment $appointment)
    {
        abort_if($appointment->tenant_id !== $this->tenantId($request), 404);
        abort_if($appointment->status === 'canceled', 422, 'Agendamento cancelado nao pode ser concluido.');

        $appointment = $this->transaction(function () use ($appointment) {
            $appointment->update(['status' => 'completed']);

            // Atendimento concluido consome uma utilizacao da assinatura do cliente.
            if ($appointment->client_subscription_id) {
                SubscriptionUsage::firstOrCreate([
                    'tenant_id' => $appointment->tenant_id,
                    'appointment_id' => $appointment->id,
                ], [
                    'client_subscription_id' => $appointment->client_subscription_id,
                    'service_id' => $appointment->service_id,
                    'used_at' => now(),
                ]);
            }

            return $appointment;
        });

        return $appointment->fresh(['client', 'professional', 'service', 'subscription.usages']);
    }

    private function hasConflict(int $tenantId, int $professionalId, Carbon $startsAt, Carbon $endsAt, ?int $ignoreId = null): bool
    {
        // Conflito existe quando os intervalos se sobrepoem no mesmo profissional.
        return Appointment::where('tenant_id', $tenantId)
            ->where('professional_id', $professionalId)
            ->where('status', 'scheduled')
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function assertSubscriptionCanBook(int $tenantId, int $clientId, int $subscriptionId, int $serviceId, Carbon $startsAt): void
    {
        // Carrega plano e servicos para validar todas as regras antes de criar agenda.
        $subscription = ClientSubscription::where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->with('plan.services')
            ->findOrFail($subscriptionId);

        abort_if($subscription->status !== 'active', 422, 'Assinatura nao esta ativa.');
        abort_if($subscription->payment_status === 'overdue', 422, 'Assinatura bloqueada por inadimplencia.');
        abort_if($subscription->ends_on && $subscription->ends_on->isPast(), 422, 'Assinatura vencida.');
        abort_unless($subscription->plan->services->contains('id', $serviceId), 422, 'Servico nao incluso no plano.');

        $plan = $subscription->plan;
        if ($plan->allowed_weekdays !== null) {
            // No Carbon, domingo = 0 e sabado = 6.
            abort_unless(in_array($startsAt->dayOfWeek, $plan->allowed_weekdays, true), 422, 'Plano nao permite agendamento neste dia.');
        }

        if ($plan->allowed_start_time && $plan->allowed_end_time) {
            $time = $startsAt->format('H:i:s');
            abort_if($time < $plan->allowed_start_time || $time > $plan->allowed_end_time, 422, 'Plano nao permite agendamento neste horario.');
        }

        if ($plan->usage_limit) {
            // Limite de uso e contado por mes calendario na Fase 0.
            $periodStart = $startsAt->copy()->startOfMonth();
            $periodEnd = $startsAt->copy()->endOfMonth();
            $used = $subscription->usages()
                ->whereBetween('used_at', [$periodStart, $periodEnd])
                ->count();

            abort_if($used >= $plan->usage_limit, 422, 'Limite mensal de uso do plano atingido.');
        }
    }
}
