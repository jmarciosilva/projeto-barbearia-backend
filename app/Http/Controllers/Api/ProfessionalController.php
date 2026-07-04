<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Support\PlanGate;
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
            ->with('services')
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
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer'],
        ]);

        // Senha informada libera acesso ao app: exige email proprio e unico entre os logins.
        if (! empty($data['password'])) {
            $request->validate([
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            ]);
        }

        $serviceIds = $data['service_ids'] ?? [];
        if ($serviceIds) {
            $found = Service::where('tenant_id', $tenantId)->whereIn('id', $serviceIds)->count();
            abort_if($found !== count(array_unique($serviceIds)), 422, 'Um ou mais servicos nao pertencem ao estabelecimento.');
        }

        // Limite de profissionais do plano SaaS (spec 3): so vale pra quem entra ja ativo.
        if ($data['is_active'] ?? true) {
            $plan = PlanGate::for($tenantId)->currentPlan();
            abort_if(
                $plan && ! PlanGate::for($tenantId)->canAddProfessional(),
                422,
                "Limite de profissionais do plano {$plan->name} atingido ({$plan->max_professionals}). Faca upgrade para adicionar mais."
            );
        }

        // Todo profissional criado pela API fica vinculado ao tenant autenticado.
        $professional = $this->transaction(function () use ($data, $tenantId, $serviceIds) {
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

            $professional = Professional::create(Arr::except($data, ['password', 'service_ids']) + [
                'tenant_id' => $tenantId,
                'user_id' => $user?->id,
            ]);

            $professional->services()->sync($serviceIds);

            return $professional;
        });

        return response()->json($professional->fresh('services'), 201);
    }

    public function update(Request $request, Professional $professional)
    {
        $tenantId = $this->tenantId($request);
        abort_if($professional->tenant_id !== $tenantId, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'commission_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer'],
        ]);

        if (array_key_exists('service_ids', $data)) {
            $serviceIds = $data['service_ids'] ?? [];
            $found = Service::where('tenant_id', $tenantId)->whereIn('id', $serviceIds)->count();
            abort_if($found !== count(array_unique($serviceIds)), 422, 'Um ou mais servicos nao pertencem ao estabelecimento.');
        }

        $this->transaction(function () use ($professional, $data) {
            $professional->update(Arr::except($data, ['service_ids']));

            // Omitir a chave em um update nao deve apagar os servicos ja habilitados.
            if (array_key_exists('service_ids', $data)) {
                $professional->services()->sync($data['service_ids'] ?? []);
            }
        });

        return $professional->fresh('services');
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
