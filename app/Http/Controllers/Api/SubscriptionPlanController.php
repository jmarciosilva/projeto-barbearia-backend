<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SubscriptionPlanController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return SubscriptionPlan::where('tenant_id', $this->tenantId($request))
            ->with('services')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validatedData($request, $tenantId);

        $plan = $this->transaction(function () use ($data, $tenantId) {
            $plan = SubscriptionPlan::create(Arr::except($data, 'services') + [
                'tenant_id' => $tenantId,
            ]);

            // O plano e seus servicos precisam ser salvos juntos para evitar plano incompleto.
            $this->syncServices($plan, $data['services'] ?? [], $tenantId);

            return $plan;
        });

        return response()->json($plan->fresh('services'), 201);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $tenantId = $this->tenantId($request);
        abort_if($subscriptionPlan->tenant_id !== $tenantId, 404);

        $subscriptionPlan = $this->transaction(function () use ($request, $tenantId, $subscriptionPlan) {
            $data = $this->validatedData($request, $tenantId, true, $subscriptionPlan->id);
            $subscriptionPlan->update(Arr::except($data, 'services'));

            if (array_key_exists('services', $data)) {
                $this->syncServices($subscriptionPlan, $data['services'], $tenantId);
            }

            return $subscriptionPlan;
        });

        return $subscriptionPlan->fresh('services');
    }

    private function validatedData(Request $request, int $tenantId, bool $partial = false, ?int $ignoreId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        // As regras abaixo representam as restricoes comerciais do plano vendido pelo salao.
        return $request->validate([
            'name' => [$required, 'string', 'max:255', Rule::unique('subscription_plans')->where('tenant_id', $tenantId)->ignore($ignoreId)],
            'description' => ['nullable', 'string'],
            'price_cents' => [$required, 'integer', 'min:0'],
            'billing_period' => ['nullable', Rule::in(['monthly'])],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
            'allowed_weekdays' => ['nullable', 'array'],
            'allowed_weekdays.*' => ['integer', 'min:0', 'max:6'],
            'allowed_start_time' => ['nullable', 'date_format:H:i'],
            'allowed_end_time' => ['nullable', 'date_format:H:i', 'after:allowed_start_time'],
            'is_active' => ['nullable', 'boolean'],
            'services' => ['nullable', 'array'],
            'services.*.id' => ['required_with:services', 'integer'],
            'services.*.included_quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'services.*.discount_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
    }

    private function syncServices(SubscriptionPlan $plan, array $services, int $tenantId): void
    {
        $serviceIds = collect($services)->pluck('id')->all();
        $found = Service::where('tenant_id', $tenantId)->whereIn('id', $serviceIds)->count();

        // Impede vincular servicos de outro estabelecimento ao plano atual.
        abort_if($found !== count(array_unique($serviceIds)), 422, 'Um ou mais servicos nao pertencem ao estabelecimento.');

        $sync = collect($services)->mapWithKeys(fn (array $service) => [
            $service['id'] => [
                'included_quantity' => $service['included_quantity'] ?? null,
                'discount_percentage' => $service['discount_percentage'] ?? 0,
            ],
        ])->all();

        $plan->services()->sync($sync);
    }
}
