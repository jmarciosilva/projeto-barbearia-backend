<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        // Senha informada libera acesso ao app: exige email proprio e unico entre os logins.
        if (! empty($data['password'])) {
            $request->validate([
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            ]);
        }

        // Telefone e unico por estabelecimento para evitar duplicidade no atendimento.
        $client = $this->transaction(function () use ($data, $tenantId) {
            $user = null;

            if (! empty($data['password'])) {
                $user = User::create([
                    'tenant_id' => $tenantId,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'role' => 'customer',
                    'password' => $data['password'],
                ]);
            }

            return Client::create(Arr::except($data, 'password') + [
                'tenant_id' => $tenantId,
                'user_id' => $user?->id,
            ]);
        });

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

    /**
     * Ficha do cliente logado (plano, pagamento e historico de uso). E a
     * unica forma de um `customer` ver os proprios dados — `GET /clients`
     * fica restrito a owner/professional para nao vazar dados entre clientes.
     */
    public function me(Request $request)
    {
        abort_unless($request->user()->role === 'customer', 403, 'Somente clientes possuem esse perfil.');

        return Client::where('tenant_id', $this->tenantId($request))
            ->where('user_id', $request->user()->id)
            ->with(['subscriptions.plan.services', 'subscriptions.usages.service', 'subscriptions.payments.receipts'])
            ->firstOrFail();
    }

    /**
     * Autoedicao do proprio perfil (nome, telefone e e-mail de contato).
     * Mesmo padrao de `ProfessionalController::updateSelf`: e-mail/senha de
     * login continuam exclusivos de `PATCH /me/credentials`, esse endpoint
     * so mexe nos dados de contato do proprio registro de `Client`.
     */
    public function updateSelf(Request $request)
    {
        abort_unless($request->user()->role === 'customer', 403, 'Somente clientes podem editar esse perfil.');

        $tenantId = $this->tenantId($request);
        $client = Client::where('tenant_id', $tenantId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:30', Rule::unique('clients')->where('tenant_id', $tenantId)->ignore($client->id)],
        ]);

        $this->transaction(fn () => $client->update($data));

        return $client->fresh();
    }
}
