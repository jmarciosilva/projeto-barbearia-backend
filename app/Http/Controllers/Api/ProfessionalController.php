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
        // Cliente monta agendamento a partir desta lista; nao deve ver profissional desativado.
        return Professional::where('tenant_id', $this->tenantId($request))
            ->when($request->user()->role === 'customer', fn ($query) => $query->where('is_active', true))
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

    /**
     * Perfil do profissional logado. Usado pela tela "Meu perfil" do app —
     * o profissional nunca ve/edita o registro de outro colega por aqui.
     */
    public function me(Request $request)
    {
        abort_unless($request->user()->role === 'professional', 403, 'Somente profissionais possuem esse perfil.');

        return $this->findOwn($request);
    }

    /**
     * Autoedicao do proprio perfil. Nao aceita `commission_percentage` nem
     * `is_active` — comissao e ativacao continuam decisao exclusiva do
     * proprietario via `PUT /professionals/{id}`.
     */
    public function updateSelf(Request $request)
    {
        abort_unless($request->user()->role === 'professional', 403, 'Somente profissionais podem editar esse perfil.');

        $professional = $this->findOwn($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:120'],
        ]);

        $this->transaction(fn () => $professional->update($data));

        return $professional->fresh();
    }

    private function findOwn(Request $request): Professional
    {
        return Professional::where('tenant_id', $this->tenantId($request))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
