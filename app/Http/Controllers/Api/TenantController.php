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
        ]);

        $tenant = Tenant::findOrFail($this->tenantId($request));

        // Atualizacao do estabelecimento tambem fica transacionada para padronizar escritas.
        $this->transaction(fn () => $tenant->update($data));

        return $tenant->fresh('saasSubscription.plan');
    }
}
