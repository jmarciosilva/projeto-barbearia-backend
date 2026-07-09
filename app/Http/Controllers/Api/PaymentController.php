<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDatabaseTransactions;
use App\Http\Controllers\Api\Concerns\UsesTenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    use RunsDatabaseTransactions;
    use UsesTenant;

    public function index(Request $request)
    {
        return Payment::where('tenant_id', $this->tenantId($request))
            ->with(['client', 'appointment.service', 'subscription.client', 'subscription.plan', 'receipts'])
            ->latest()
            ->get();
    }

    public function me(Request $request)
    {
        abort_unless($request->user()->role === 'customer', 403, 'Somente clientes possuem pagamentos proprios.');

        $client = Client::where('tenant_id', $this->tenantId($request))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return Payment::where('tenant_id', $this->tenantId($request))
            ->where('client_id', $client->id)
            ->with(['appointment.service', 'subscription.plan', 'receipts'])
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
            'method' => ['nullable', Rule::in(['pix', 'credit_card', 'debit_card', 'cash', 'fiado'])],
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

        return response()->json($payment->fresh(['client', 'appointment.service', 'subscription.client', 'receipts']), 201);
    }

    public function markPaid(Request $request, Payment $payment)
    {
        abort_if($payment->tenant_id !== $this->tenantId($request), 404);

        // Profissional so confirma o pagamento do proprio atendimento avulso (mesma
        // regra ja aplicada em AppointmentController::complete); pagamento sem
        // atendimento vinculado (ex: assinatura) tambem fica fora do alcance dele.
        if ($request->user()->role === 'professional') {
            abort_if(
                $payment->appointment?->professional?->user_id !== $request->user()->id,
                403,
                'Voce so pode confirmar pagamento dos proprios atendimentos.'
            );
        }

        $data = $request->validate([
            'method' => ['required', Rule::in(['pix', 'credit_card', 'debit_card', 'cash', 'fiado'])],
        ]);

        $payment = $this->transaction(function () use ($payment, $data) {
            if ($data['method'] === 'fiado') {
                $payment->update([
                    'method' => 'fiado',
                    'status' => 'pending',
                    'paid_at' => null,
                ]);

                return $payment;
            }

            $payment->update([
                'method' => $data['method'],
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

        return $payment->fresh(['client', 'appointment.service', 'subscription.client', 'receipts']);
    }

    public function receive(Request $request, Payment $payment)
    {
        abort_if($payment->tenant_id !== $this->tenantId($request), 404);

        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['pix', 'credit_card', 'debit_card', 'cash'])],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        abort_if($payment->status === 'paid', 422, 'Pagamento ja esta quitado.');
        abort_if($data['amount_cents'] > $payment->remaining_cents, 422, 'Valor recebido maior que o saldo pendente.');

        $payment = $this->transaction(function () use ($payment, $data) {
            PaymentReceipt::create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'amount_cents' => $data['amount_cents'],
                'method' => $data['method'],
                'received_at' => $data['received_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->refresh();

            if ($payment->remaining_cents <= 0) {
                $payment->update([
                    'method' => $data['method'],
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                if ($payment->client_subscription_id) {
                    $payment->subscription->update([
                        'payment_status' => 'paid',
                        'last_payment_at' => $payment->paid_at,
                    ]);
                }
            }

            return $payment;
        });

        return $payment->fresh(['client', 'appointment.service', 'subscription.client', 'receipts']);
    }
}
