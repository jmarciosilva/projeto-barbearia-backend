<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SaasPlan;
use App\Models\SaasSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use RunsDatabaseTransactions;

    public function registerOwner(Request $request)
    {
        $data = $request->validate([
            'tenant.name' => ['required', 'string', 'max:255'],
            'tenant.business_type' => ['nullable', 'string', Rule::in(['barbershop', 'beauty_salon', 'aesthetic_clinic', 'nails', 'brows_lashes', 'spa', 'other'])],
            'tenant.document' => ['nullable', 'string', 'max:30'],
            'tenant.email' => ['nullable', 'email', 'max:255'],
            'tenant.phone' => ['nullable', 'string', 'max:30'],
            'tenant.address' => ['nullable', 'string', 'max:255'],
            'tenant.city' => ['nullable', 'string', 'max:120'],
            'tenant.state' => ['nullable', 'string', 'size:2'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner.phone' => ['nullable', 'string', 'max:30'],
            'owner.password' => ['required', 'string', 'min:8'],
        ]);

        // Onboarding cria tenant, proprietario e assinatura SaaS como uma unica operacao.
        $payload = $this->transaction(function () use ($data) {
            $tenant = Tenant::create($data['tenant'] + [
                'business_type' => $data['tenant']['business_type'] ?? 'beauty_salon',
                'status' => 'trial',
            ]);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'phone' => $data['owner']['phone'] ?? null,
                'role' => 'owner',
                'password' => $data['owner']['password'],
            ]);

            SaasSubscription::create([
                'tenant_id' => $tenant->id,
                'saas_plan_id' => SaasPlan::where('code', 'trial')->value('id'),
                'plan_name' => 'Trial (Premium por 30 dias)',
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(30),
            ]);

            return compact('tenant', 'owner');
        });

        return response()->json([
            'token' => $payload['owner']->createToken('mobile')->plainTextToken,
            'user' => $payload['owner'],
            'tenant' => $payload['tenant']->load('saasSubscription.plan'),
        ], 201);
    }

    /**
     * Autocadastro do cliente (spec: onboarding e autocadastro), sem
     * depender do dono cadastrar manualmente via `POST /clients`. O cliente
     * chega a um tenant por convite (`invite_code`, vindo de link/QR do
     * dono) ou por escolha no diretorio publico (`tenant_id`).
     */
    public function registerClient(Request $request)
    {
        $data = $request->validate([
            'invite_code' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'client.name' => ['required', 'string', 'max:255'],
            'client.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'client.phone' => ['required', 'string', 'max:30'],
            'client.password' => ['required', 'string', 'min:8'],
        ]);

        abort_if(
            empty($data['invite_code']) && empty($data['tenant_id']),
            422,
            'Informe um codigo de convite ou escolha um estabelecimento.'
        );

        $tenant = ! empty($data['invite_code'])
            ? Tenant::where('invite_code', strtoupper($data['invite_code']))->first()
            : Tenant::find($data['tenant_id']);

        abort_if(! $tenant, 422, 'Estabelecimento nao encontrado para o codigo/id informado.');

        // Telefone e unico por estabelecimento, mesma regra do cadastro manual (ClientController::store).
        $request->validate([
            'client.phone' => [Rule::unique('clients', 'phone')->where('tenant_id', $tenant->id)],
        ]);

        $payload = $this->transaction(function () use ($data, $tenant) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['client']['name'],
                'email' => $data['client']['email'],
                'phone' => $data['client']['phone'],
                'role' => 'customer',
                'password' => $data['client']['password'],
            ]);

            $client = Client::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => $data['client']['name'],
                'email' => $data['client']['email'],
                'phone' => $data['client']['phone'],
            ]);

            return compact('tenant', 'user', 'client');
        });

        return response()->json([
            'token' => $payload['user']->createToken('mobile')->plainTextToken,
            'user' => $payload['user'],
            'client' => $payload['client'],
            'tenant' => $payload['tenant']->only(['id', 'name', 'business_type', 'city']),
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // Usuario inativo nao pode gerar token, mesmo com senha correta.
        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais invalidas.'],
            ]);
        }

        return [
            'token' => $user->createToken('mobile')->plainTextToken,
            'user' => $user->load('tenant.saasSubscription.plan'),
        ];
    }

    public function me(Request $request)
    {
        return $request->user()->load('tenant.saasSubscription.plan');
    }

    /**
     * Troca o proprio e-mail e/ou senha de login, exigindo a senha atual por
     * seguranca (evita que uma sessao esquecida aberta troque a credencial
     * sem o dono da conta perceber). Vale para qualquer papel — e o
     * `User.email`/`password` (login), nao o e-mail de contato guardado em
     * `Client`/`Professional`, que continua independente.
     */
    public function updateCredentials(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Senha atual incorreta.'],
            ]);
        }

        abort_if(
            ! isset($data['email']) && ! isset($data['password']),
            422,
            'Informe um novo e-mail ou uma nova senha.'
        );

        $this->transaction(function () use ($user, $data) {
            $user->update(array_filter([
                'email' => $data['email'] ?? null,
                'password' => $data['password'] ?? null,
            ]));
        });

        return $user->fresh()->load('tenant.saasSubscription.plan');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
