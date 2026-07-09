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

    // Corrige um lancamento ja confirmado (valor/metodo/observacao digitados
    // errado, ex: dono lancou o mesmo pagamento duas vezes com metodos
    // diferentes). Nao mexe em status/paid_at — isso continua exclusivo de
    // markPaid/receive, que sao transicoes de confirmacao, nao edicao.
    public function update(Request $request, Payment $payment)
    {
        abort_if($payment->tenant_id !== $this->tenantId($request), 404);

        $data = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['pix', 'credit_card', 'debit_card', 'cash'])],
            'notes' => ['nullable', 'string'],
        ]);

        // Nao deixa o valor cair abaixo do que ja foi recebido via recibo de
        // verdade (nao usa o accessor received_cents aqui: pra um pagamento
        // confirmado de uma vez so, sem nenhum PaymentReceipt, esse accessor
        // so devolve o proprio amount_cents de volta — comparar contra ele
        // bloquearia qualquer correcao de valor, mesmo sem nenhum recibo
        // real de por meio).
        if (isset($data['amount_cents'])) {
            $actuallyReceivedCents = (int) $payment->receipts()->sum('amount_cents');
            abort_if(
                $data['amount_cents'] < $actuallyReceivedCents,
                422,
                'O valor nao pode ser menor que o que ja foi recebido.'
            );
        }

        $payment = $this->transaction(fn () => tap($payment)->update($data));

        return $payment->fresh(['client', 'appointment.service', 'subscription.client', 'subscription.plan', 'receipts']);
    }

    // Remove um lancamento errado (ex: pagamento duplicado). Se o pagamento
    // era a prova de que uma assinatura estava paga, recalcula
    // payment_status — so volta pra "pending" se nenhum outro pagamento
    // dessa assinatura continuar com status=paid (senao um duplicado
    // removido derrubaria uma assinatura que continua paga de verdade).
    public function destroy(Request $request, Payment $payment)
    {
        abort_if($payment->tenant_id !== $this->tenantId($request), 404);

        $this->transaction(function () use ($payment) {
            $subscriptionId = $payment->client_subscription_id;
            $payment->delete();

            if ($subscriptionId) {
                $subscription = ClientSubscription::find($subscriptionId);
                $stillPaid = $subscription?->payments()->where('status', 'paid')->exists();

                if ($subscription && ! $stillPaid) {
                    $subscription->update(['payment_status' => 'pending']);
                }
            }
        });

        return response()->noContent();
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
