# API do Clube do Salao — Fase 0

Contrato de payloads da API para a integracao do app Flutter (`D:\PROJETO_BARBEARIA\mobile`). Base URL local: `http://localhost:8000/api` (ou a porta usada por `php artisan serve`).

## Autenticacao

Sanctum com token em `Authorization: Bearer {token}`. Todo endpoint autenticado exige esse header, exceto `/auth/register-owner`, `/auth/login` e `/health`.

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

### `POST /auth/logout` — qualquer papel autenticado

Revoga o token atual. Resposta `204`.

## Tenant

### `GET /tenant` — qualquer papel autenticado

Dados do estabelecimento do usuario logado (por `tenant_id`).

### `PATCH /tenant` — somente `owner`

```json
{ "name": "Novo nome", "address": "Rua X, 100", "city": "Sao Paulo", "state": "SP" }
```

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
  "password": "senha12345"
}
```

Sem `password`, o profissional e criado como registro de negocio apenas (sem login).

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

### `GET /subscription-plans` — `owner`, `professional`

Inclui `services` (pivot com `included_quantity` e `discount_percentage`).

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
  ]
}
```

`allowed_weekdays` usa a convencao do Carbon: domingo=0 ... sabado=6. Omitir `usage_limit`/`allowed_weekdays`/horarios = sem restricao.

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

### `GET /appointments` — `owner`, `professional`

Filtros opcionais via query string: `?from=2026-07-01&to=2026-07-31` (`starts_at` entre as datas). Profissional recebe automaticamente so a propria agenda (filtrado pelo `Professional` vinculado ao login); proprietario ve a agenda inteira do estabelecimento. Nao existe listagem para `customer` — cliente ve os proprios agendamentos futuros apenas na resposta do `POST /appointments` que ele mesmo criar.

### `PUT/PATCH /appointments/{id}` — `owner`, `professional`

Remarcar (`starts_at`/`professional_id`) refaz a checagem de conflito. Tambem aceita `status` (`scheduled`|`canceled`|`completed`|`no_show`) e `cancellation_reason`.

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

`method`: `pix` | `cash` | `card` | `other` (default `pix`). Quando `status=paid` (na criacao ou via `mark-paid`), a assinatura vinculada e atualizada automaticamente (`payment_status=paid`, `last_payment_at`).

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
