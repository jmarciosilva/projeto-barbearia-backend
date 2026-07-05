# API do Clube do Salao — Fases 0 e 1

Contrato de payloads da API para a integracao do app Flutter (`D:\PROJETO_BARBEARIA\mobile`). Base URL local: `http://localhost:8000/api` (ou a porta usada por `php artisan serve`).

## Autenticacao

Sanctum com token em `Authorization: Bearer {token}`. Todo endpoint autenticado exige esse header, exceto `/auth/register-owner`, `/auth/register-client`, `/auth/login`, `/tenants/by-invite-code/{code}`, `/tenants/directory` e `/health`.

Papeis (`role`): `owner`, `professional`, `customer`. Cada rota abaixo indica quais papeis tem acesso.

### `POST /auth/register-owner` — publico

Cria o tenant (estabelecimento), o usuario proprietario e a assinatura SaaS trial, tudo em uma transacao.

```json
// Requisicao
{
  "tenant": { "name": "Clube do Salao", "business_type": "barbershop", "phone": "11999990000" },
  "owner": { "name": "Jose Silva", "email": "owner@example.com", "password": "senha12345" }
}
```

```json
// Resposta 201
{ "token": "1|abc...", "user": { "id": 1, "role": "owner", "tenant_id": 1, ... }, "tenant": { "id": 1, "name": "...", "saas_subscription": { ... } } }
```

### `POST /auth/register-client` — publico

Autocadastro do cliente (Fase 3 — Onboarding e Autocadastro): cria o `User` (role `customer`) e o `Client`, ja vinculados ao tenant certo, e retorna token. O cliente chega ao tenant de duas formas — informe **uma** delas:

- `invite_code`: codigo de convite do estabelecimento (o dono compartilha por link/QR — ver `GET /tenants/by-invite-code/{code}`).
- `tenant_id`: id de um estabelecimento escolhido no diretorio publico (`GET /tenants/directory`), quando o cliente nao recebeu convite de ninguem.

```json
// Requisicao (via convite)
{
  "invite_code": "AB3XQ9",
  "client": { "name": "Maria Cliente", "email": "maria@example.com", "phone": "11988887777", "password": "senha12345" }
}
```

```json
// Resposta 201
{ "token": "3|ghi...", "user": { "id": 5, "role": "customer", "tenant_id": 1 }, "client": { "id": 3, "name": "Maria Cliente" }, "tenant": { "id": 1, "name": "Clube do Salao", "business_type": "barbershop", "city": "Sao Paulo" } }
```

Cadastro entra ativo na hora, sem aprovacao manual do dono — a confirmacao de pagamento continua sendo feita separadamente, como ja funciona hoje. `422` quando nenhum dos dois campos de vinculo e informado, quando o codigo/id nao corresponde a um estabelecimento, ou quando o telefone ja esta em uso nesse mesmo tenant.

### `GET /tenants/by-invite-code/{code}` — publico

Usado pela tela de confirmacao do convite antes do cliente preencher o proprio cadastro. Retorna so `id`, `name`, `business_type` e `city`. `404` quando o codigo nao existe.

### `GET /tenants/directory` — publico

Lista todos os estabelecimentos (mesmos 4 campos de `by-invite-code`), ordenados por nome, para o cliente sem convite escolher onde se cadastrar.

### `POST /tenant/invite-code/regenerate` — somente `owner`

Troca o `invite_code` do proprio tenant, invalidando o anterior (quem tiver o link/QR antigo nao consegue mais se cadastrar por ele). Retorna o tenant atualizado.

### `POST /auth/login` — publico

Funciona para qualquer papel (`owner`, `professional`, `customer`) — desde que o usuario tenha sido criado com senha (ver `POST /professionals` e `POST /clients`).

```json
// Requisicao
{ "email": "owner@example.com", "password": "senha12345" }
```

```json
// Resposta 200
{ "token": "2|def...", "user": { "id": 1, "role": "owner", "tenant": { ... } } }
```

Credenciais invalidas ou usuario sem senha cadastrada retornam `422` com `{"errors": {"email": ["Credenciais invalidas."]}}`.

### `GET /me` — qualquer papel autenticado

Retorna o usuario logado com `tenant.saas_subscription` carregados.

### `PATCH /me/credentials` — qualquer papel autenticado

Troca o proprio e-mail e/ou senha de login. Exige a senha atual por seguranca (evita que uma sessao esquecida aberta troque a credencial sem o dono da conta perceber). Informe **pelo menos um** de `email`/`password`.

```json
{ "current_password": "senhaAtual123", "email": "novo@example.com", "password": "novaSenha123", "password_confirmation": "novaSenha123" }
```

Resposta 200 com o usuario atualizado (mesmo formato de `GET /me`). `422` quando `current_password` esta errada, quando nenhum de `email`/`password` e informado, ou quando o novo `email` ja esta em uso por outro usuario. Este endpoint continua liberado mesmo com o trial vencido (junto com `/auth/logout` e `/saas-subscription`), senao o dono ficaria trancado sem conseguir nem corrigir a propria senha. So altera a credencial de login (`User.email`/`password`) — o e-mail de contato guardado em `Client`/`Professional` continua independente.

### `POST /auth/logout` — qualquer papel autenticado

Revoga o token atual. Resposta `204`.

## Tenant

### `GET /tenant` — qualquer papel autenticado

Dados do estabelecimento do usuario logado (por `tenant_id`).

### `PATCH /tenant` — somente `owner`

```json
{ "name": "Novo nome", "address": "Rua X, 100", "city": "Sao Paulo", "state": "SP", "professional_payment_day": 5 }
```

`professional_payment_day` define o dia do mes usado no extrato de comissao dos profissionais.

`invite_code` (tambem presente na resposta) e o codigo que o dono compartilha com o cliente para autocadastro (ver `POST /auth/register-client`); é gerado sozinho na criacao do tenant e so muda via `POST /tenant/invite-code/regenerate`.

## Planos SaaS

Modelo de negocio do produto (spec, secao 3): todo tenant novo comeca em
trial de 30 dias com os limites/funcionalidades do Premium; depois disso,
o dono escolhe um dos 3 tiers pagos. `tenant.saas_subscription` (retornado
por `GET /tenant`, login, `GET /me` e onboarding) sempre traz:

```json
{
  "status": "trial",
  "effective_status": "trial",
  "trial_days_remaining": 27,
  "plan": { "code": "trial", "name": "Trial (Premium por 30 dias)", "price_cents": 0 },
  "limits": { "professionals": 3, "client_subscriptions": 20, "units": 1 },
  "usage": { "professionals": 1, "client_subscriptions": 2, "units": 1 }
}
```

`effective_status` vira `trial_expired` (sem nada ser gravado no banco)
assim que `trial_ends_at` passa sem o dono escolher um plano pago. A partir
desse momento, **toda rota de escrita** (POST/PATCH/PUT/DELETE) retorna
`402` ate o dono trocar de plano; leitura nunca e bloqueada. `PATCH
/saas-subscription` e `POST /auth/logout` continuam liberados mesmo com o
trial vencido, para o dono sempre ter uma saida.

### `GET /saas-plans` — somente `owner`

Lista os 3 tiers pagos disponiveis (trial nao aparece aqui — e automatico).

```json
[
  { "code": "basico", "name": "Basico", "price_cents": 7999, "max_professionals": 3, "max_client_subscriptions": 100, "max_units": 1 },
  { "code": "intermediario", "name": "Intermediario", "price_cents": 12999, "max_professionals": 8, "max_client_subscriptions": 400, "max_units": 1 },
  { "code": "premium", "name": "Premium", "price_cents": 19999, "max_professionals": null, "max_client_subscriptions": null, "max_units": null }
]
```

`null` num limite significa ilimitado.

### `PATCH /saas-subscription` — somente `owner`

```json
{ "plan_code": "basico" }
```

Troca efetiva na hora (sem cobranca real ainda — gateway fica para fase
futura). Se o uso atual exceder o novo limite (ex: 6 profissionais ativos
indo para o Basico, limite 3), a regra de downgrade (spec 3.5) entra em
acao automaticamente: os registros mais antigos continuam ativos dentro do
novo limite, os excedentes viram inativos (profissional com `is_active:
false`, assinatura de cliente com `status: paused`) — nada e apagado, e o
dono pode reativar manualmente ou fazer upgrade de novo. Retorna o tenant
atualizado (mesmo formato de `GET /tenant`).

Criar um profissional ou assinar/trocar o plano de um cliente quando o
limite do plano SaaS ja foi atingido retorna `422` com uma mensagem
explicando qual limite bateu.

## Auto-perfil

Cada rota abaixo confere o proprio papel do usuario logado internamente (nao usa o middleware `role:...`) e so le/edita o registro vinculado a ele mesmo — nunca o de outra pessoa.

### `GET /me/client` — somente `customer`

Ficha do cliente logado: dados basicos, `subscriptions.plan` e `subscriptions.usages.service` (historico de uso real). `403` para `owner`/`professional`.

### `GET /me/professional` — somente `professional`

Perfil do profissional logado (inclui `commission_percentage`, so leitura). `403` para `owner`/`customer`.

### `PATCH /me/professional` — somente `professional`

```json
{ "name": "Ana Souza", "phone": "11988887777", "specialty": "Cortes e barba" }
```

Aceita apenas `name`, `email`, `phone`, `specialty`. **Nao** aceita `commission_percentage` nem `is_active` — esses campos continuam exclusivos do proprietario via `PUT /professionals/{id}`; se enviados, sao silenciosamente ignorados.

## Profissionais

### `GET /professionals` — `owner`, `professional`, `customer`

Cliente ve somente profissionais com `is_active=true` (para montar agendamento); `owner`/`professional` veem todos.

### `POST /professionals` — somente `owner`

`email` + `password` sao opcionais: quando informados, cria tambem um login (`role=professional`) vinculado ao registro.

```json
// Requisicao (com acesso ao app)
{
  "name": "Ana Souza",
  "email": "ana@example.com",
  "phone": "11988887777",
  "specialty": "Cortes e barba",
  "commission_percentage": 40,
  "password": "senha12345",
  "service_ids": [1, 2]
}
```

Sem `password`, o profissional e criado como registro de negocio apenas (sem login). `service_ids` e opcional (spec 4.1: quais servicos esse profissional executa) — precisa pertencer ao mesmo tenant, senao `422`. Tambem pode ser enviado em `update`; omitir a chave mantem a lista atual, enviar `[]` limpa os servicos habilitados.

### `PUT/PATCH /professionals/{id}` — somente `owner`

Mesmos campos do `store`, todos `sometimes`. Nao permite alterar senha/email de login por essa rota.

## Clientes

### `GET /clients` — `owner`, `professional`

Inclui `subscriptions.plan`. Lista o tenant inteiro — por isso e restrita a staff; cliente usa `GET /me/client` para ver so os proprios dados.

### `POST /clients` — `owner`, `professional`

`phone` e obrigatorio e unico por tenant. `email` + `password` opcionais criam login (`role=customer`), igual ao fluxo de profissionais.

```json
{
  "name": "Carlos Mendes",
  "phone": "11988881234",
  "email": "carlos@example.com",
  "password": "senha12345",
  "birth_date": "1990-05-20",
  "notes": "Prefere maquina 2 nas laterais."
}
```

### `PUT/PATCH /clients/{id}` — `owner`, `professional`

Aceita tambem `status`: `active` | `inactive` | `blocked`.

## Servicos

### `GET /services` — `owner`, `professional`, `customer`

Cliente ve somente servicos com `is_active=true`; `owner`/`professional` veem todos.

### `POST /services` / `PUT/PATCH /services/{id}` — somente `owner`

```json
{ "name": "Corte masculino", "duration_minutes": 45, "price_cents": 6000, "description": "..." }
```

`price_cents` e `duration_minutes` sao a base usada pelo agendamento (duracao) e pelos planos.

## Planos de assinatura

### `GET /subscription-plans` — `owner`, `professional`, `customer`

Inclui `services` (pivot com `included_quantity` e `discount_percentage`) e `professionals` (spec 4.2: quais profissionais atendem assinantes deste plano). Cliente ve somente planos com `is_active=true` (para escolher/trocar de assinatura); `owner`/`professional` veem todos.

### `POST /subscription-plans` / `PUT/PATCH /subscription-plans/{id}` — somente `owner`

```json
{
  "name": "Bronze",
  "price_cents": 9990,
  "usage_limit": 4,
  "allowed_weekdays": [1, 2, 3, 4, 5],
  "allowed_start_time": "08:00",
  "allowed_end_time": "18:00",
  "services": [
    { "id": 1, "included_quantity": 4 },
    { "id": 2, "included_quantity": 4, "discount_percentage": 20 }
  ],
  "professional_ids": [1, 2]
}
```

`allowed_weekdays` usa a convencao do Carbon: domingo=0 ... sabado=6. Omitir `usage_limit`/`allowed_weekdays`/horarios = sem restricao. `professional_ids` e opcional; precisa pertencer ao mesmo tenant, senao `422`. No `update`, omitir a chave mantem a lista atual — enviar `[]` limpa a restricao (mesma semantica de `services`).

## Assinaturas de cliente

### `GET /client-subscriptions` — `owner`, `professional`

Inclui `client` e `plan.services`.

### `POST /client-subscriptions` — `owner`, `professional`

```json
{
  "client_id": 5,
  "subscription_plan_id": 2,
  "starts_on": "2026-07-01",
  "renews_on": "2026-08-01",
  "payment_status": "pending"
}
```

`status` inicial e sempre `active`; `payment_status` default `pending` se omitido.

### `PUT/PATCH /client-subscriptions/{id}` — somente `owner`

Campos: `status` (`active`|`paused`|`overdue`|`canceled`|`expired`), `payment_status` (`paid`|`pending`|`overdue`), `renews_on`, `ends_on`, `notes`.

## Agenda

### `POST /appointments` — qualquer papel autenticado

Se o usuario logado for `customer`, o `client_id` enviado e **ignorado** e substituido pelo `Client` vinculado ao proprio usuario — um cliente nunca agenda em nome de outro.

```json
{
  "client_id": 5,
  "professional_id": 2,
  "service_id": 1,
  "client_subscription_id": 7,
  "starts_at": "2026-07-06 10:00:00",
  "notes": "Cliente chega 10 min antes."
}
```

`client_subscription_id` e opcional (agendamento avulso sem plano). Quando informado, a API valida nesta ordem e retorna `422` com mensagem especifica se falhar:

1. assinatura `active` e nao `overdue`
2. assinatura nao vencida (`ends_on`)
3. servico incluso no plano
4. dia da semana permitido (`allowed_weekdays`)
5. horario permitido (`allowed_start_time`/`allowed_end_time`)
6. limite mensal de uso (`usage_limit`, contado por mes calendario)

Conflito de horario do profissional tambem retorna `422` (`"Profissional ja possui agendamento neste horario."`), independente de haver assinatura.

**Agendamento avulso**: quando `client_subscription_id` e omitido e o servico tem `price_cents`, a API cria automaticamente um `Payment` (`status=pending`, `amount_cents` = preco do servico, vinculado ao `client_id` e ao `appointment_id`) na mesma transacao — ele aparece pronto em `GET /payments` pra confirmacao manual, igual a um pagamento de assinatura. A resposta do `POST /appointments` inclui esse pagamento em `payment` (`null` quando ha assinatura ou o servico nao tem preco).

### `GET /appointments` — `owner`, `professional`, `customer`

Filtros opcionais via query string: `?from=2026-07-01&to=2026-07-31` (`starts_at` entre as datas). Profissional recebe automaticamente so a propria agenda; cliente recebe automaticamente so os proprios agendamentos (filtrado pelo `Client` vinculado ao login); proprietario ve a agenda inteira do estabelecimento.

### `PUT/PATCH /appointments/{id}` — `owner`, `professional`, `customer`

Remarcar (`starts_at`/`professional_id`) refaz a checagem de conflito. `owner`/`professional` tambem podem enviar `status` (`scheduled`|`canceled`|`completed`|`no_show`) e `cancellation_reason`. Cliente so altera o proprio agendamento e tem campos restritos pelo controller.

### `POST /appointments/{id}/complete` — `owner`, `professional`

Profissional so conclui os proprios atendimentos (`403` caso contrario); proprietario conclui qualquer um. Marca `status=completed` e registra `SubscriptionUsage` quando ha `client_subscription_id`.

## Pagamentos

### `GET /payments` / `POST /payments` / `POST /payments/{id}/mark-paid` — somente `owner`

```json
// POST /payments
{
  "client_subscription_id": 7,
  "amount_cents": 9990,
  "method": "pix",
  "status": "pending",
  "due_on": "2026-07-10"
}
```

`method`: `pix` | `credit_card` | `debit_card` | `cash` | `fiado` (default `pix` na criacao).

Em `POST /payments/{id}/mark-paid`, o proprietario deve informar a modalidade escolhida:

```json
{ "method": "credit_card" }
```

Se a modalidade for `pix`, `credit_card`, `debit_card` ou `cash`, o pagamento vira `paid` e a assinatura vinculada e atualizada automaticamente (`payment_status=paid`, `last_payment_at`). Se a modalidade for `fiado`, o pagamento fica `pending`, sem `paid_at`, para continuar aparecendo como divida em aberto.

Pagamento avulso (sem assinatura) usa `client_id` e/ou `appointment_id` no lugar de `client_subscription_id` — pelo menos um dos tres e obrigatorio, senao `422`. Na pratica, o pagamento avulso normalmente **nem precisa ser criado manualmente**: `POST /appointments` ja cria um automaticamente quando o agendamento nao tem assinatura (ver secao Agenda). `GET /payments` retorna `client`, `appointment.service` e `subscription.client` carregados, cobrindo os dois tipos de pagamento na mesma tela.

### `POST /payments/{id}/receipts` — somente `owner`

Lanca um recebimento parcial em um pagamento pendente/fiado.

```json
{ "amount_cents": 4000, "method": "pix", "notes": "Entrada" }
```

`method`: `pix` | `credit_card` | `debit_card` | `cash`. Se a soma dos recebimentos (`receipts`) atingir o valor total, o pagamento vira `paid`; caso contrario, segue `pending` com `remaining_cents` indicando o saldo.

### `GET /me/payments` — somente `customer`

Retorna os pagamentos do cliente logado, incluindo `receipts`, para separar pendentes e quitados no app.

## Comissoes e adiantamentos

### `GET /me/professional/finance` — somente `professional`

Extrato do profissional logado. Query opcional: `?period=week` ou `?period=month` (default `month`).

```json
{
  "completed_count": 6,
  "gross_cents": 36000,
  "commission_percentage": 40,
  "commission_cents": 14400,
  "advances_cents": 3000,
  "net_cents": 11400,
  "payment_day": 5,
  "advances": []
}
```

### `GET /professionals/{id}/finance` — somente `owner`

Mesmo extrato, mas consultado pelo proprietario para gestao do salao.

### `POST /professionals/{id}/advances` — somente `owner`

Lanca um adiantamento ao profissional, abatido do extrato do periodo.

```json
{ "amount_cents": 3000, "notes": "Adiantamento semanal" }
```

## Fila de espera

Pedido de "atendimento no estabelecimento" de um cliente sem assinatura, sem escolher profissional nem horario — o staff atribui manualmente quando surge uma vaga.

### `GET /waitlist` — `owner`, `professional`, `customer`

Cliente ve somente as propias entradas; `owner`/`professional` veem a fila inteira do estabelecimento. Filtro opcional `?status=waiting`. Inclui `client`, `service`, `professional` (preferencia, pode ser `null`) e `appointment` (preenchido apos `assign`).

### `POST /waitlist` — `owner`, `professional`, `customer`

```json
{ "service_id": 1, "professional_id": null, "notes": "Prefere de tarde" }
```

Cliente logado sempre entra na propria fila (`client_id` enviado e ignorado, igual ao `POST /appointments`). `owner`/`professional` podem cadastrar um cliente existente (`client_id` obrigatorio nesse caso, para atender um walk-in). `professional_id` e opcional — `null` significa "qualquer profissional"; quando informado, precisa poder realizar o `service_id` (spec 4.1). Toda entrada nasce com `status=waiting`.

### `PATCH /waitlist/{id}` — `owner`, `professional`, `customer`

Unico uso hoje e cancelar: `{ "status": "canceled" }`. Cliente so cancela a propria entrada (`403` senao); staff cancela qualquer uma. So funciona enquanto `status=waiting` (`422` caso contrario).

### `POST /waitlist/{id}/assign` — somente `owner`, `professional`

```json
{ "professional_id": 2, "starts_at": "2026-07-06 10:00:00" }
```

Transforma a entrada num `Appointment` de verdade (sempre avulso, sem `client_subscription_id`), reaproveitando as mesmas checagens de `POST /appointments`: `professional_id` precisa poder realizar o servico (spec 4.1) e nao pode haver conflito de horario (`422` em ambos os casos). `professional_id` no corpo e opcional se a entrada ja tiver uma preferencia salva (usa a preferencia nesse caso); senao e obrigatorio. Cria o pagamento avulso automaticamente (mesma regra do agendamento direto) e marca a entrada como `status=scheduled`, com `appointment_id` preenchido. So funciona em entradas `waiting` (`422` se ja atendida/cancelada).

## Formato de erro padrao

Toda excecao da API e normalizada em JSON (`bootstrap/app.php`):

| Situacao | Status | Corpo |
|---|---|---|
| Validacao | 422 | `{"message": "Dados invalidos.", "error": "validation_error", "errors": {...}}` |
| Sem token / token invalido | 401 | `{"message": "Autenticacao obrigatoria.", "error": "unauthenticated"}` |
| Papel sem permissao (`role:...` middleware) | 403 | `{"message": "Acesso nao autorizado para este papel.", "error": "http_error"}` |
| Registro nao encontrado | 404 | `{"message": "Registro nao encontrado.", "error": "not_found"}` |
| Regra de negocio (`abort_if`/`abort_unless` com mensagem propria) | 422/403/etc | `{"message": "<mensagem especifica>", "error": "http_error"}` |
| Erro de banco | 500 | `{"message": "Erro ao acessar dados.", "error": "database_error"}` |
| Erro inesperado | 500 | `{"message": "Erro interno inesperado.", "error": "internal_server_error"}` |

## Dados de demonstracao

`php artisan db:seed` cria um tenant "Clube do Salao Demo" com login pronto para os 3 papeis (senha `demo12345` para todos):

- Proprietario: `owner@clubedosalao.com`
- Profissional: `ana.souza@clubedosalao.com` (tambem existe `rafael.souza@clubedosalao.com`)
- Cliente: `carlos.mendes@clubedosalao.com`

Inclui 4 servicos, 3 planos (Bronze/Prata/Black — mesmos nomes usados nos mocks do Flutter), 3 clientes com assinaturas (uma delas `payment_status=pending` para testar a tela de confirmacao de pagamento) e alguns agendamentos de exemplo.
