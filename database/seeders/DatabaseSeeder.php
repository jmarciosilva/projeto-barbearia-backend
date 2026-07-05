<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Payment;
use App\Models\Professional;
use App\Models\SaasPlan;
use App\Models\SaasSubscription;
use App\Models\Service;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Senha padrao de todas as contas de demonstracao.
     */
    private const DEMO_PASSWORD = 'demo12345';

    /**
     * Popula um tenant completo de demonstracao, com os tres papeis (owner,
     * professional, customer) prontos para login no app mobile. Os nomes de
     * clientes, servicos e planos espelham os mocks ja validados no Flutter,
     * para a transicao de dados mockados para dados reais ser transparente.
     */
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Clube do Salao Demo',
            'business_type' => 'barbershop',
            'email' => 'contato@clubedosalao.com',
            'phone' => '11999990000',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'status' => 'active',
            // O seeder usa `WithoutModelEvents`, entao o hook `Tenant::booted()`
            // que gera o invite_code sozinho nao dispara aqui — precisa setar direto.
            'invite_code' => Tenant::generateInviteCode(),
        ]);

        SaasSubscription::create([
            'tenant_id' => $tenant->id,
            'saas_plan_id' => SaasPlan::where('code', 'trial')->value('id'),
            'plan_name' => 'Trial (Premium por 30 dias)',
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(30),
        ]);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jose Silva',
            'email' => 'owner@clubedosalao.com',
            'phone' => '11988880000',
            'role' => 'owner',
            'password' => self::DEMO_PASSWORD,
        ]);

        $professionals = collect([
            ['name' => 'Ana Souza', 'email' => 'ana.souza@clubedosalao.com', 'specialty' => 'Cortes e barba', 'commission_percentage' => 40],
            ['name' => 'Rafael Souza', 'email' => 'rafael.souza@clubedosalao.com', 'specialty' => 'Sobrancelha e coloracao', 'commission_percentage' => 35],
        ])->map(function (array $data) use ($tenant) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => 'professional',
                'password' => self::DEMO_PASSWORD,
            ]);

            return Professional::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'specialty' => $data['specialty'],
                'commission_percentage' => $data['commission_percentage'],
                'is_active' => true,
            ]);
        });

        $services = collect([
            ['name' => 'Corte masculino', 'duration_minutes' => 45, 'price_cents' => 6000],
            ['name' => 'Barba completa', 'duration_minutes' => 30, 'price_cents' => 4000],
            ['name' => 'Sobrancelha', 'duration_minutes' => 20, 'price_cents' => 3000],
            ['name' => 'Coloracao', 'duration_minutes' => 90, 'price_cents' => 12000],
        ])->map(fn (array $data) => Service::create($data + ['tenant_id' => $tenant->id]));

        [$corte, $barba, $sobrancelha, $coloracao] = $services;

        $bronze = SubscriptionPlan::create([
            'tenant_id' => $tenant->id,
            'name' => 'Bronze',
            'description' => 'Corte ilimitado de segunda a sexta.',
            'price_cents' => 9990,
            'usage_limit' => 4,
            'allowed_weekdays' => [1, 2, 3, 4, 5],
        ]);
        $bronze->services()->sync([$corte->id => ['included_quantity' => 4]]);

        $prata = SubscriptionPlan::create([
            'tenant_id' => $tenant->id,
            'name' => 'Prata',
            'description' => 'Corte e barba com desconto em produtos.',
            'price_cents' => 14990,
            'usage_limit' => 8,
        ]);
        $prata->services()->sync([
            $corte->id => ['included_quantity' => 8],
            $barba->id => ['included_quantity' => 8, 'discount_percentage' => 20],
        ]);

        $black = SubscriptionPlan::create([
            'tenant_id' => $tenant->id,
            'name' => 'Black',
            'description' => 'Uso ilimitado de todos os servicos.',
            'price_cents' => 19990,
        ]);
        $black->services()->sync([
            $corte->id => [],
            $barba->id => [],
            $sobrancelha->id => [],
            $coloracao->id => [],
        ]);

        $carlos = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Carlos Mendes',
            'phone' => '11988881234',
            'user_id' => User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Carlos Mendes',
                'email' => 'carlos.mendes@clubedosalao.com',
                'role' => 'customer',
                'password' => self::DEMO_PASSWORD,
            ])->id,
        ]);

        $joao = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Joao Ribeiro',
            'phone' => '11977775678',
        ]);

        $marina = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Marina Alves',
            'phone' => '11966664321',
        ]);

        $carlosSubscription = ClientSubscription::create([
            'tenant_id' => $tenant->id,
            'client_id' => $carlos->id,
            'subscription_plan_id' => $bronze->id,
            'status' => 'active',
            'payment_status' => 'paid',
            'starts_on' => Carbon::today()->subMonth(),
            'renews_on' => Carbon::today()->addDays(15),
            'last_payment_at' => now()->subDays(15),
        ]);

        $joaoSubscription = ClientSubscription::create([
            'tenant_id' => $tenant->id,
            'client_id' => $joao->id,
            'subscription_plan_id' => $black->id,
            'status' => 'active',
            'payment_status' => 'pending',
            'starts_on' => Carbon::today()->subDays(5),
            'renews_on' => Carbon::today()->addDays(25),
        ]);

        ClientSubscription::create([
            'tenant_id' => $tenant->id,
            'client_id' => $marina->id,
            'subscription_plan_id' => $prata->id,
            'status' => 'active',
            'payment_status' => 'paid',
            'starts_on' => Carbon::today()->subMonth(),
            'renews_on' => Carbon::today()->addDays(20),
            'last_payment_at' => now()->subDays(10),
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'client_subscription_id' => $carlosSubscription->id,
            'amount_cents' => 9990,
            'method' => 'pix',
            'status' => 'paid',
            'paid_at' => now()->subDays(15),
        ]);

        // Pagamento pendente: mesmo cenario que a tela de "pagamentos pendentes" do app mockado.
        Payment::create([
            'tenant_id' => $tenant->id,
            'client_subscription_id' => $joaoSubscription->id,
            'amount_cents' => 19990,
            'method' => 'pix',
            'status' => 'pending',
            'due_on' => Carbon::today()->addDays(3),
        ]);

        Appointment::create([
            'tenant_id' => $tenant->id,
            'client_id' => $carlos->id,
            'professional_id' => $professionals[0]->id,
            'service_id' => $corte->id,
            'client_subscription_id' => $carlosSubscription->id,
            'starts_at' => Carbon::today()->setTime(9, 0),
            'ends_at' => Carbon::today()->setTime(9, 45),
            'status' => 'scheduled',
        ]);

        Appointment::create([
            'tenant_id' => $tenant->id,
            'client_id' => $joao->id,
            'professional_id' => $professionals[0]->id,
            'service_id' => $barba->id,
            'client_subscription_id' => $joaoSubscription->id,
            'starts_at' => Carbon::today()->setTime(10, 30),
            'ends_at' => Carbon::today()->setTime(11, 0),
            'status' => 'scheduled',
        ]);

        Appointment::create([
            'tenant_id' => $tenant->id,
            'client_id' => $marina->id,
            'professional_id' => $professionals[1]->id,
            'service_id' => $sobrancelha->id,
            'starts_at' => Carbon::yesterday()->setTime(14, 0),
            'ends_at' => Carbon::yesterday()->setTime(14, 20),
            'status' => 'completed',
        ]);

        $this->command?->info('Tenant de demonstracao criado. Login de teste (senha "'.self::DEMO_PASSWORD.'" para todos):');
        $this->command?->info('  Proprietario: owner@clubedosalao.com');
        $this->command?->info('  Profissional: ana.souza@clubedosalao.com');
        $this->command?->info('  Cliente:      carlos.mendes@clubedosalao.com');
    }
}
