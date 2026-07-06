<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function show(Request $request)
    {
        return Tenant::with('saasSubscription.plan')->findOrFail($this->tenantId($request));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', 'string', Rule::in(['barbershop', 'beauty_salon', 'aesthetic_clinic', 'nails', 'brows_lashes', 'spa', 'other'])],
            'document' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'professional_payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
            'break_start_time' => ['nullable', 'date_format:H:i'],
            'break_end_time' => ['nullable', 'date_format:H:i', 'after:break_start_time'],
        ]);

        $tenant = Tenant::findOrFail($this->tenantId($request));

        // Atualizacao do estabelecimento tambem fica transacionada para padronizar escritas.
        $this->transaction(fn () => $tenant->update($data));

        return $tenant->fresh('saasSubscription.plan');
    }

    /**
     * Regenera o codigo de convite do proprio estabelecimento, invalidando o
     * anterior. Exclusivo do dono (rota protegida por `role:owner`).
     */
    public function regenerateInviteCode(Request $request)
    {
        $tenant = Tenant::findOrFail($this->tenantId($request));

        $this->transaction(fn () => $tenant->update(['invite_code' => Tenant::generateInviteCode()]));

        return $tenant->fresh('saasSubscription.plan');
    }

    /**
     * Consulta publica (sem autenticacao) usada pela tela de confirmacao do
     * convite: so expoe o minimo necessario para o cliente reconhecer o
     * salao antes de completar o proprio cadastro.
     */
    public function byInviteCode(string $code)
    {
        $tenant = Tenant::select(['id', 'name', 'business_type', 'city'])
            ->where('invite_code', strtoupper($code))
            ->first();

        abort_if(! $tenant, 404, 'Codigo de convite invalido.');

        return $tenant;
    }

    /**
     * Diretorio publico (sem autenticacao) para o cliente avulso, sem
     * convite de ninguem, escolher o salao onde quer se cadastrar. So expoe
     * dado publico/nao sensivel de cada estabelecimento.
     */
    public function directory()
    {
        return Tenant::select(['id', 'name', 'business_type', 'city'])
            ->orderBy('name')
            ->get();
    }
}
