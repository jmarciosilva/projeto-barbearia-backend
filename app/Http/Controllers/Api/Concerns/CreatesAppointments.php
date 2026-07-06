<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantScheduleOverride;
use Carbon\Carbon;

/**
 * Regras compartilhadas entre AppointmentController e WaitlistController na
 * hora de transformar uma intencao de atendimento em um Appointment de verdade.
 */
trait CreatesAppointments
{
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

    /**
     * Horario de funcionamento e pausa (ex: almoco) do tenant, com excecao
     * pontual por data (ver TenantScheduleOverride). Tenant sem nada
     * configurado (opening/closing_time nulos) mantem o comportamento
     * anterior, sem nenhuma restricao de horario.
     */
    private function assertWithinBusinessHours(int $tenantId, Carbon $startsAt, Carbon $endsAt): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (! $tenant->opening_time && ! $tenant->closing_time) {
            return;
        }

        $override = TenantScheduleOverride::where('tenant_id', $tenantId)
            ->where('date', $startsAt->toDateString())
            ->first();

        abort_if($override?->is_closed, 422, 'O salao esta fechado nesta data.');

        $opensAt = $override->opens_at ?? $tenant->opening_time;
        $closesAt = $override->closes_at ?? $tenant->closing_time;

        if ($opensAt && $closesAt) {
            abort_if(
                $startsAt->format('H:i:s') < $opensAt || $endsAt->format('H:i:s') > $closesAt,
                422,
                'Fora do horario de funcionamento do salao.'
            );
        }

        if ($tenant->break_start_time && $tenant->break_end_time) {
            $overlapsBreak = $startsAt->format('H:i:s') < $tenant->break_end_time
                && $endsAt->format('H:i:s') > $tenant->break_start_time;

            abort_if($overlapsBreak, 422, 'O salao esta fechado para pausa neste horario.');
        }
    }

    private function assertProfessionalCanPerformService(Professional $professional, Service $service): void
    {
        // Restricao de quem executa cada servico (spec 4.1): so se aplica quando o
        // profissional tem alguma lista de servicos definida; sem lista, sem restricao.
        $professionalServiceIds = $professional->services()->pluck('services.id');

        if ($professionalServiceIds->isNotEmpty()) {
            abort_unless($professionalServiceIds->contains($service->id), 422, 'Profissional nao realiza este servico.');
        }
    }

    /**
     * Agendamento sem assinatura (avulso) gera automaticamente um pagamento
     * pendente pelo preco de catalogo do servico, reaproveitando a mesma tela
     * de confirmacao manual de pagamento ja usada pelas assinaturas.
     */
    private function createAvulsoPaymentIfNeeded(Appointment $appointment, Service $service): void
    {
        if ($appointment->client_subscription_id || ! $service->price_cents) {
            return;
        }

        Payment::create([
            'tenant_id' => $appointment->tenant_id,
            'client_id' => $appointment->client_id,
            'appointment_id' => $appointment->id,
            'amount_cents' => $service->price_cents,
            'method' => 'pix',
            'status' => 'pending',
            'due_on' => $appointment->starts_at->toDateString(),
        ]);
    }
}
