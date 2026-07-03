<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\ClientSubscription;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return Payment::where('tenant_id', $this->tenantId($request))
            ->with('subscription.client')
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'client_subscription_id' => ['nullable', 'integer'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['pix', 'cash', 'card', 'other'])],
            'status' => ['nullable', Rule::in(['paid', 'pending', 'overdue'])],
            'due_on' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! empty($data['client_subscription_id'])) {
            ClientSubscription::where('tenant_id', $tenantId)->findOrFail($data['client_subscription_id']);
        }

        $payment = $this->transaction(function () use ($data, $tenantId) {
            $payment = Payment::create($data + [
                'tenant_id' => $tenantId,
                'method' => $data['method'] ?? 'pix',
                'status' => $data['status'] ?? 'pending',
            ]);

            // Pagamento confirmado atualiza a assinatura na mesma transacao.
            if ($payment->status === 'paid' && $payment->client_subscription_id) {
                $payment->subscription->update([
                    'payment_status' => 'paid',
                    'last_payment_at' => $payment->paid_at ?? now(),
                ]);
            }

            return $payment;
        });

        return response()->json($payment->fresh('subscription.client'), 201);
    }

    public function markPaid(Request $request, Payment $payment)
    {
        abort_if($payment->tenant_id !== $this->tenantId($request), 404);

        $payment = $this->transaction(function () use ($payment) {
            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Mantem pagamento e assinatura sincronizados para evitar inadimplencia falsa.
            if ($payment->client_subscription_id) {
                $payment->subscription->update([
                    'payment_status' => 'paid',
                    'last_payment_at' => $payment->paid_at,
                ]);
            }

            return $payment;
        });

        return $payment->fresh('subscription.client');
    }
}
