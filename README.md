# Clube do Salão — API

API headless em **Laravel 12** (PHP 8.2+) para o Clube do Salão: backend multi-tenant de um SaaS de gestão por assinatura mensal para barbearias, salões de beleza, clínicas de estética e negócios similares. Serve exclusivamente o app Flutter mobile-only em `../mobile` — não há painel web administrativo (decisão de produto permanente).

## Stack técnica

- **Framework:** Laravel `^12.0` (resolvido: 12.62.0), PHP `^8.2`
- **Autenticação:** Laravel Sanctum `^4.0` — tokens Bearer (`createToken('mobile')`), sem expiração automática, sem uso de sessão SPA stateful. Middleware `auth:sanctum` protege quase todo o grupo autenticado.
- **Banco de dados:** MySQL em desenvolvimento e produção (`DB_CONNECTION=mysql`, utf8mb4, modo estrito). Testes rodam isolados em SQLite `:memory:`. O projeto migrou de SQLite para MySQL local cedo no desenvolvimento porque o SQLite não força integridade referencial e escondia bugs reais de migration.
- **Fila/cache/sessão:** tudo via tabelas do próprio MySQL (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`) — sem Redis obrigatório. Não há Jobs assíncronos implementados ainda (`app/Jobs` não existe).
- **Qualidade de código:** Laravel Pint `^1.24` (preset padrão) para formatação; PHPUnit `^11.5` (não Pest) para testes. Sem Larastan/PHPStan configurado.
- **Integrações externas:** nenhuma implementada ainda. Cobrança recorrente via **Asaas** e notificações push via **Firebase Cloud Messaging** estão previstas na especificação de produto, mas hoje todo pagamento é confirmado manualmente e não há disparo de push — ver seção "Fora de escopo hoje".

## Multi-tenancy

Isolamento por `tenant_id` é **manual, por controller** (sem Global Scope automático nem trait de model):

- Trait `UsesTenant` (`app/Http/Controllers/Api/Concerns/UsesTenant.php`) resolve `tenant_id` a partir do usuário autenticado (`$request->user()->tenant_id`) e aborta com 403 se o usuário não tiver estabelecimento vinculado.
- Cada controller de recurso filtra manualmente suas queries por esse `tenant_id` e valida unicidade escopada por tenant.
- Em rotas com model binding, a posse é checada explicitamente após o bind, retornando **404** (não 403) para registros de outro tenant, evitando vazar a existência do dado.
- `App\Models\User.tenant_id` é a fonte da verdade; é `nullable` apenas para `role=admin`, que opera fora de qualquer tenant.
- Controle de acesso por **papel** é uma camada à parte: middleware `EnsureRole` (alias `role:owner,professional,customer,admin`), sem Policies do Laravel — autorização e validação ficam inline nos controllers (`$request->validate([...])`, sem FormRequest customizado).
- Middleware `EnsureTenantPlanIsActive` (alias `plan.active`) bloqueia escrita (não leitura) com `402` quando o trial/assinatura SaaS do estabelecimento está vencida, com exceções para logout, consulta/alteração da própria assinatura SaaS e troca de credenciais — para o dono nunca ficar trancado sem saída.

## Estrutura

```
app/
├── Console/Commands/
│   └── CreateAdminCommand.php        # php artisan admin:create {name} {email} {password}
├── Http/
│   ├── Controllers/Api/              # todos os controllers, sob namespace Api
│   │   └── Concerns/                 # UsesTenant, RunsDatabaseTransactions, CreatesAppointments
│   └── Middleware/
│       ├── EnsureRole.php            # alias "role:..."
│       └── EnsureTenantPlanIsActive.php  # alias "plan.active"
└── Models/                           # 19 models Eloquent (ver "Entidades" abaixo)
routes/api.php                        # todas as rotas da API
database/migrations/                  # ~30 migrations, evolução por fase do roadmap
tests/Feature/                        # 1 arquivo de teste por fase do roadmap
docs/api.md                           # contrato de payloads request/response por endpoint
```

### Entidades principais (`app/Models`)

- **Conta/plataforma:** `User`, `Tenant`, `SaasPlan`, `SaasSubscription`, `AdminSubscriptionGrant` (ledger de cortesia concedida por admin)
- **Cadastros do estabelecimento:** `Professional`, `Client`, `Service`, `SubscriptionPlan`
- **Agenda:** `Appointment`, `WaitlistEntry`, `ProfessionalWorkingHour`, `ProfessionalScheduleOverride`, `TenantScheduleOverride`
- **Assinatura do cliente e uso:** `ClientSubscription`, `SubscriptionUsage`
- **Financeiro:** `Payment` (suporta pagamento parcial/"fiado" via `PaymentReceipt`, com accessors `received_cents`/`remaining_cents`/`is_fully_paid`), `ProfessionalAdvance`

## Regras de negócio implementadas

Espelham as regras descritas em `../mobile/README.md` (o consumidor desta API é o app Flutter). Resumo do que já está no backend:

- **Duas camadas de assinatura:** `SaasSubscription` (estabelecimento paga a plataforma, 4 tiers: Trial 30 dias, Básico R$79,99, Intermediário R$129,99, Premium R$199,99) e `ClientSubscription` (cliente final paga o estabelecimento por um `SubscriptionPlan`).
- **Downgrade/limites nunca bloqueiam**: ao reduzir plano SaaS, registros excedentes ao novo limite apenas são inativados, nunca removidos.
- **Selo "fundador"** (`tenants.is_founder`) e concessão gratuita de assinatura por admin, com ledger de auditoria (`AdminSubscriptionGrant`).
- **Planos de assinatura do cliente** com serviços inclusos, limite de uso mensal, dias da semana e faixa de horário permitidos.
- **Agenda** com verificação de conflito por profissional, horário de trabalho individual por dia da semana, exceções pontuais de horário (por profissional e por estabelecimento), conclusão de atendimento com registro de uso da assinatura.
- **Fila de espera** (`WaitlistEntry`) para cliente sem assinatura, atribuída manualmente a uma vaga pelo dono/profissional.
- **Pagamentos 100% manuais**: PIX, dinheiro, cartão (débito/crédito) ou fiado, com recebimento parcial e extrato de comissão/adiantamento por profissional.
- **Onboarding e autocadastro**: código de convite único por tenant, diretório público de estabelecimentos, autocadastro do cliente via convite ou diretório.
- **Painel Inteligente do Proprietário**: resumo do dia, ocupação de agenda, risco de não-retorno de cliente, desempenho por profissional.
- **Administração da plataforma**: dashboard global (tenants ativos/trial/expirados/fundadores, receita projetada), listagem de tenants, alternar selo de fundador, estender assinatura manualmente.

Detalhes de cada fase e o histórico de decisões estão em `roadmap.md`. Contrato completo de request/response por endpoint está em `docs/api.md`.

### Fora de escopo hoje

- Cobrança recorrente automática (gateway Asaas) — pagamento é sempre confirmado manualmente.
- Notificações push (Firebase Cloud Messaging).
- Painel web administrativo — deliberadamente fora de escopo, o produto é mobile-only.
- Fidelidade/pontos, avaliações, CRM avançado/estoque, marketing automation, BI, IA — previstos na especificação de produto, ainda não iniciados no backend.

## Rotas da API

Visão geral por área (lista completa e payloads em `docs/api.md`, ou rode `php artisan route:list --path=api` para o inventário exato):

- **Públicas:** `POST /auth/register-owner`, `POST /auth/register-client`, `POST /auth/login`, `GET /tenants/by-invite-code/{code}`, `GET /tenants/directory`, `GET /health`
- **Admin da plataforma** (`role:admin`, prefixo `/admin`): dashboard, listagem de tenants, alternar fundador, estender assinatura
- **Conta autenticada:** `GET /me`, `PATCH /me/credentials`, `POST /auth/logout`, além dos endpoints `me/*` de cliente e profissional (perfil, financeiro, exceções de horário, assinatura, pagamentos)
- **Catálogo e agenda** (leitura por `owner`/`professional`/`customer`): profissionais, serviços, planos, agendamentos, agenda do salão, exceções de horário do estabelecimento
- **Fila de espera:** listar, criar, atualizar, atribuir vaga
- **Operação diária** (`owner`/`professional`): clientes, assinaturas de clientes, conclusão de atendimento, confirmação de pagamento
- **Gestão do estabelecimento** (`owner`): dados do tenant, código de convite, exceções de horário, planos SaaS, cadastro/edição de profissionais/serviços/planos, financeiro (pagamentos, recibos, adiantamentos), dashboard (resumo, ocupação, desempenho da equipe, risco de retorno)

## Comandos

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

> Atenção: `migrate:fresh` apaga todos os dados do banco configurado em `.env`. Nunca rode em um banco com dados reais sem confirmar antes qual `DB_DATABASE` está ativo.

```powershell
php artisan route:list --path=api
php artisan test
vendor\bin\pint
php artisan admin:create "Nome" admin@exemplo.com senha-forte
```

`composer dev` roda em paralelo `serve` + `queue:listen` + `pail` (logs) + `npm run dev`.
