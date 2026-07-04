<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\CreatesAppointments;
use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Professional;
use App\Models\Service;
use App\Models\WaitlistEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fila de espera para "atendimento no estabelecimento": cliente sem
 * assinatura pede para ser atendido quando tiver vaga, sem escolher
 * profissional nem horario. O staff atribui manualmente (ver assign()).
 */
class WaitlistController extends Controller
{
    use CreatesAppointments;
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        // Cliente so ve as propias entradas; staff ve a fila inteira do estabelecimento.
        $ownClientId = $request->user()->role === 'customer'
            ? Client::where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->value('id')
            : null;

        return WaitlistEntry::where('tenant_id', $tenantId)
            ->with(['client', 'service', 'professional', 'appointment'])
            ->when($ownClientId, fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'service_id' => ['required', 'integer'],
            'professional_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        // Cliente logado sempre entra na propria fila, nunca na de outro cliente.
        if ($request->user()->role === 'customer') {
            $data['client_id'] = Client::where('tenant_id', $tenantId)
                ->where('user_id', $request->user()->id)
                ->firstOrFail()
                ->id;
        } else {
            abort_if(empty($data['client_id']), 422, 'Informe o cliente.');
            Client::where('tenant_id', $tenantId)->findOrFail($data['client_id']);
        }

        $service = Service::where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($data['service_id']);

        // Preferencia de profissional e opcional (spec do produto: fila e "qualquer
        // profissional"); quando informada, ja checa a restricao de servico (4.1).
        if (! empty($data['professional_id'])) {
            $professional = Professional::where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($data['professional_id']);
            $this->assertProfessionalCanPerformService($professional, $service);
        }

        $entry = $this->transaction(fn () => WaitlistEntry::create($data + [
            'tenant_id' => $tenantId,
            'status' => 'waiting',
        ]));

        return response()->json($entry->fresh(['client', 'service', 'professional']), 201);
    }

    public function update(Request $request, WaitlistEntry $waitlistEntry)
    {
        $tenantId = $this->tenantId($request);
        abort_if($waitlistEntry->tenant_id !== $tenantId, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['canceled'])],
        ]);

        // Cliente so cancela a propria entrada; staff cancela qualquer uma da fila.
        if ($request->user()->role === 'customer') {
            abort_if($waitlistEntry->client?->user_id !== $request->user()->id, 403, 'Voce so pode cancelar a propria entrada na fila.');
        }

        abort_unless($waitlistEntry->status === 'waiting', 422, 'Somente entradas aguardando podem ser canceladas.');

        $this->transaction(fn () => $waitlistEntry->update($data));

        return $waitlistEntry->fresh(['client', 'service', 'professional']);
    }

    /**
     * Staff atribui um horario vago a um cliente da fila, criando o
     * agendamento avulso de verdade (com cobranca automatica quando o
     * servico tem preco) e fechando a entrada da fila.
     */
    public function assign(Request $request, WaitlistEntry $waitlistEntry)
    {
        $tenantId = $this->tenantId($request);
        abort_if($waitlistEntry->tenant_id !== $tenantId, 404);
        abort_unless($waitlistEntry->status === 'waiting', 422, 'Esta entrada ja foi atendida ou cancelada.');

        $data = $request->validate([
            'professional_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
        ]);

        // Usa o profissional preferido da entrada quando o cliente ja escolheu um;
        // senao, o staff informa quem esta livre agora.
        $professionalId = $data['professional_id'] ?? $waitlistEntry->professional_id;
        abort_if(! $professionalId, 422, 'Informe o profissional.');

        $professional = Professional::where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($professionalId);
        $service = Service::where('tenant_id', $tenantId)->where('is_active', true)->findOrFail($waitlistEntry->service_id);

        $this->assertProfessionalCanPerformService($professional, $service);

        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = $startsAt->copy()->addMinutes($service->duration_minutes);

        abort_if($this->hasConflict($tenantId, $professional->id, $startsAt, $endsAt), 422, 'Profissional ja possui agendamento neste horario.');

        $waitlistEntry = $this->transaction(function () use ($waitlistEntry, $professional, $service, $startsAt, $endsAt, $tenantId) {
            $appointment = Appointment::create([
                'tenant_id' => $tenantId,
                'client_id' => $waitlistEntry->client_id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'scheduled',
            ]);

            $this->createAvulsoPaymentIfNeeded($appointment, $service);

            $waitlistEntry->update([
                'status' => 'scheduled',
                'professional_id' => $professional->id,
                'appointment_id' => $appointment->id,
            ]);

            return $waitlistEntry;
        });

        return $waitlistEntry->fresh(['client', 'service', 'professional', 'appointment.payment']);
    }
}
