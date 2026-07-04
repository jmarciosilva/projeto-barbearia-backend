<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
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
            ->with(['client', 'appointment.service', 'subscription.client'])
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'client_subscription_id' => ['nullable', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['pix', 'cash', 'card', 'other'])],
            'status' => ['nullable', Rule::in(['paid', 'pending', 'overdue'])],
            'due_on' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        // Todo pagamento precisa apontar pra alguem: assinatura (recorrente) ou
        // cliente direto (avulso), senao fica sem dono nas telas de cobranca.
        abort_if(
            empty($data['client_subscription_id']) && empty($data['client_id']) && empty($data['appointment_id']),
            422,
            'Informe client_subscription_id ou client_id.'
        );

        $subscription = null;
        if (! empty($data['client_subscription_id'])) {
            $subscription = ClientSubscription::where('tenant_id', $tenantId)->findOrFail($data['client_subscription_id']);
        }

        $appointment = null;
        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::where('tenant_id', $tenantId)->findOrFail($data['appointment_id']);
            $data['client_id'] = $data['client_id'] ?? $appointment->client_id;
        }

        if (! empty($data['client_id'])) {
            Client::where('tenant_id', $tenantId)->findOrFail($data['client_id']);
        } elseif ($subscription) {
            // client_subscription_id sem client_id explicito: deriva da propria assinatura.
            $data['client_id'] = $subscription->client_id;
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

        return response()->json($payment->fresh(['client', 'appointment.service', 'subscription.client']), 201);
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

        return $payment->fresh(['client', 'appointment.service', 'subscription.client']);
    }
}
