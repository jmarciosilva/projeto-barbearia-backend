<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Controller;
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

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
