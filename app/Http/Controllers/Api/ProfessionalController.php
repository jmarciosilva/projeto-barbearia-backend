<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Professional;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'commission_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Todo profissional criado pela API fica vinculado ao tenant autenticado.
        $professional = $this->transaction(fn () => Professional::create($data + [
            'tenant_id' => $this->tenantId($request),
        ]));

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
