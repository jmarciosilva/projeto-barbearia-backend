<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\TenantScheduleOverride;
use Illuminate\Http\Request;

/**
 * Excecoes pontuais ao horario padrao do estabelecimento (ver
 * `CreatesAppointments::assertWithinBusinessHours`). Exclusivo do dono.
 */
class TenantScheduleOverrideController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return TenantScheduleOverride::where('tenant_id', $this->tenantId($request))
            ->orderBy('date')
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'is_closed' => ['nullable', 'boolean'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i', 'after:opens_at'],
        ]);

        $override = $this->transaction(fn () => TenantScheduleOverride::updateOrCreate(
            ['tenant_id' => $tenantId, 'date' => $data['date']],
            [
                'is_closed' => $data['is_closed'] ?? false,
                'opens_at' => $data['opens_at'] ?? null,
                'closes_at' => $data['closes_at'] ?? null,
            ]
        ));

        return response()->json($override, 201);
    }

    public function destroy(Request $request, TenantScheduleOverride $scheduleOverride)
    {
        abort_if($scheduleOverride->tenant_id !== $this->tenantId($request), 404);

        $this->transaction(fn () => $scheduleOverride->delete());

        return response()->noContent();
    }
}
