<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ProfessionalController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return Professional::where('tenant_id', $this->tenantId($request))
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'commission_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        // Senha informada libera acesso ao app: exige email proprio e unico entre os logins.
        if (! empty($data['password'])) {
            $request->validate([
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            ]);
        }

        // Todo profissional criado pela API fica vinculado ao tenant autenticado.
        $professional = $this->transaction(function () use ($data, $tenantId) {
            $user = null;

            if (! empty($data['password'])) {
                $user = User::create([
                    'tenant_id' => $tenantId,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'role' => 'professional',
                    'password' => $data['password'],
                ]);
            }

            return Professional::create(Arr::except($data, 'password') + [
                'tenant_id' => $tenantId,
                'user_id' => $user?->id,
            ]);
        });

        return response()->json($professional, 201);
    }

    public function update(Request $request, Professional $professional)
    {
        abort_if($professional->tenant_id !== $this->tenantId($request), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'commission_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->transaction(fn () => $professional->update($data));

        return $professional;
    }
}
