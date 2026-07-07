<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Models\ProfessionalScheduleOverride;
use Illuminate\Http\Request;

/**
 * Ajuste pontual do proprio horario de trabalho para uma data especifica
 * (ex: hoje comecou mais tarde, ou nao vai trabalhar hoje), sem alterar o
 * horario recorrente cadastrado pelo dono. Exclusivo do proprio profissional
 * logado — nunca ajusta o horario de outro colega.
 */
class ProfessionalScheduleOverrideController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function me(Request $request)
    {
        return ProfessionalScheduleOverride::where(
            'professional_id',
            $this->findOwnProfessional($request)->id
        )->orderBy('date', 'desc')->get();
    }

    public function storeMe(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $professional = $this->findOwnProfessional($request);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'is_off' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
        ]);

        $isOff = $data['is_off'] ?? false;

        if (! $isOff) {
            abort_if(
                empty($data['starts_at']) || empty($data['ends_at']),
                422,
                'Informe inicio e fim, ou marque como folga.'
            );
            abort_if($data['ends_at'] <= $data['starts_at'], 422, 'Horario de fim deve ser depois do inicio.');
        }

        $override = $this->transaction(fn () => ProfessionalScheduleOverride::updateOrCreate(
            ['tenant_id' => $tenantId, 'professional_id' => $professional->id, 'date' => $data['date']],
            [
                'is_off' => $isOff,
                'starts_at' => $isOff ? null : $data['starts_at'],
                'ends_at' => $isOff ? null : $data['ends_at'],
            ]
        ));

        return response()->json($override, 201);
    }

    public function destroyMe(Request $request, ProfessionalScheduleOverride $scheduleOverride)
    {
        abort_if(
            $scheduleOverride->professional_id !== $this->findOwnProfessional($request)->id,
            404
        );

        $this->transaction(fn () => $scheduleOverride->delete());

        return response()->noContent();
    }

    private function findOwnProfessional(Request $request): Professional
    {
        abort_unless($request->user()->role === 'professional', 403, 'Somente profissionais ajustam o proprio horario.');

        return Professional::where('tenant_id', $this->tenantId($request))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
