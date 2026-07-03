<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientSubscriptionController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return ClientSubscription::where('tenant_id', $this->tenantId($request))
            ->with(['client', 'plan.services'])
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'subscription_plan_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'renews_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'payment_status' => ['nullable', Rule::in(['paid', 'pending', 'overdue'])],
            'notes' => ['nullable', 'string'],
        ]);

        // Cliente e plano devem pertencer ao mesmo tenant para preservar isolamento de dados.
        abort_unless(Client::where('tenant_id', $tenantId)->whereKey($data['client_id'])->exists(), 422, 'Cliente invalido.');
        abort_unless(SubscriptionPlan::where('tenant_id', $tenantId)->whereKey($data['subscription_plan_id'])->exists(), 422, 'Plano invalido.');

        $subscription = $this->transaction(fn () => ClientSubscription::create($data + [
            'tenant_id' => $tenantId,
            'status' => 'active',
            'payment_status' => $data['payment_status'] ?? 'pending',
        ]));

        return response()->json($subscription->fresh(['client', 'plan.services']), 201);
    }

    public function update(Request $request, ClientSubscription $clientSubscription)
    {
        abort_if($clientSubscription->tenant_id !== $this->tenantId($request), 404);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'paused', 'overdue', 'canceled', 'expired'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'pending', 'overdue'])],
            'renews_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->transaction(fn () => $clientSubscription->update($data));

        return $clientSubscription->fresh(['client', 'plan.services']);
    }
}
