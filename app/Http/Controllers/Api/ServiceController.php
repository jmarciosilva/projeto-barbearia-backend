<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        // Cliente monta agendamento a partir desta lista; nao deve ver servico desativado.
        return Service::where('tenant_id', $this->tenantId($request))
            ->when($request->user()->role === 'customer', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('services')->where('tenant_id', $tenantId)],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Servicos sao a base para planos e agenda; por isso sempre recebem tenant_id.
        $service = $this->transaction(fn () => Service::create($data + [
            'tenant_id' => $tenantId,
        ]));

        return response()->json($service, 201);
    }

    public function update(Request $request, Service $service)
    {
        $tenantId = $this->tenantId($request);
        abort_if($service->tenant_id !== $tenantId, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('services')->where('tenant_id', $tenantId)->ignore($service->id)],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:720'],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->transaction(fn () => $service->update($data));

        return $service;
    }
}
