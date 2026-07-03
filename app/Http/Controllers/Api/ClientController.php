<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return Client::where('tenant_id', $this->tenantId($request))
            ->with('subscriptions.plan')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('clients')->where('tenant_id', $tenantId)],
            'birth_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        // Telefone e unico por estabelecimento para evitar duplicidade no atendimento.
        $client = $this->transaction(fn () => Client::create($data + [
            'tenant_id' => $tenantId,
        ]));

        return response()->json($client, 201);
    }

    public function update(Request $request, Client $client)
    {
        $tenantId = $this->tenantId($request);
        abort_if($client->tenant_id !== $tenantId, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:30', Rule::unique('clients')->where('tenant_id', $tenantId)->ignore($client->id)],
            'birth_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'blocked'])],
            'notes' => ['nullable', 'string'],
        ]);

        $this->transaction(fn () => $client->update($data));

        return $client->fresh('subscriptions.plan');
    }
}
