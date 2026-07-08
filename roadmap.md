# Roadmap de Desenvolvimento - Backend

Este documento guia e audita a evolucao da API do Clube do Salao. Toda fase deve ser marcada aqui com status, escopo entregue, testes executados, pendencias e decisao de continuidade.

Fonte da verdade de produto: `../mobile/clube-do-salao-especificacao-produto.md`. Toda fase nova ou revisada deve referenciar a secao correspondente da especificacao.

## Legenda de status

- `Nao iniciado`: ainda nao entrou em desenvolvimento.
- `Em andamento`: fase ou item em implementacao.
- `Em auditoria`: implementacao concluida, aguardando revisao tecnica/funcional.
- `Aprovado`: criterios de aceite atendidos.
- `Bloqueado`: impedimento externo ou decisao pendente.

## Regras de auditoria

- Nenhuma fase deve ser considerada aprovada sem migracoes, testes e rotas principais verificadas.
- Mudancas de escopo devem ser registradas na secao "Decisoes".
- Bugs encontrados em piloto real devem virar itens auditaveis antes de novas features.
- Integracoes externas so entram depois de fluxo manual validado.

## Fase 0 - Fundacao e Validacao

Status: `Em auditoria`

Objetivo: validar o modelo de assinatura com estabelecimentos reais, usando controle manual de cobranca e foco no nucleo de recorrencia.

### Escopo backend

- [x] Setup Laravel API headless
- [x] Laravel Sanctum para autenticacao mobile
- [x] Schema multi-tenant por `tenant_id`
- [x] Onboarding de estabelecimento e proprietario
- [x] Usuarios com papeis iniciais
- [x] Cadastro de profissionais
- [x] Cadastro de clientes
- [x] Cadastro de servicos
- [x] Planos de assinatura com servicos inclusos
- [x] Restricoes por limite de uso, dias e horarios
- [x] Assinatura de cliente a um plano
- [x] Status manual de pagamento
- [x] Agenda por profissional
- [x] Verificacao de conflito de horario
- [x] Conclusao de atendimento com registro de uso
- [x] Pagamentos manuais
- [x] Comentarios de manutencao em portugues do Brasil
- [x] Tratamento global de excecoes JSON para API
- [x] Transacoes explicitas com commit e rollback nos fluxos de escrita
- [x] Regras de acesso por papel em middleware/policies
- [x] Seeds de demonstracao
- [x] Cobertura de testes para inadimplencia, limite de uso e restricoes de horario
- [x] Documentacao de payloads da API
- [x] Leitura de catalogo (servicos/profissionais) liberada para cliente, filtrada a itens ativos
- [x] Endpoints de auto-perfil (`GET /me/client`, `GET /me/professional`, `PATCH /me/professional`) com autoedicao restrita — profissional nao altera a propria comissao
- [x] Agenda (`GET /appointments`) auto-escopada por profissional
- [x] Servicos habilitados por profissional (spec 4.1: pivot profissional/servico)
- [x] Profissionais habilitados por plano (spec 4.2: restricao de plano)
- [x] Agenda do salao inteiro visivel ao cliente (`GET /appointments/salon`), sem nome/dado de outro cliente
- [x] Autoedicao do proprio perfil pelo cliente (`PATCH /me/client`: nome/telefone/e-mail de contato)

### Criterios de aceite

- [x] `php artisan migrate:fresh --force` executa sem erros
- [x] `php artisan test` executa sem erros
- [x] `php artisan route:list --path=api` lista rotas esperadas
- [x] Teste automatizado cobre rollback em falha de plano
- [x] Teste automatizado cobre erro JSON padronizado
- [x] Fluxo completo testado via cliente HTTP
- [ ] Validacao funcional com ao menos 1 estabelecimento piloto

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-03 | Codex | Parcial aprovado | Migracoes, rotas e teste de fluxo principal passaram | Regras por papel, seeds, docs de payload e testes adicionais |
| 2026-07-03 | Codex | Parcial aprovado | Comentarios em PT-BR, handler global de excecoes, transacoes explicitas e testes de rollback/JSON | Ainda faltam policies por papel e testes de regras negativas especificas |
| 2026-07-03 | Claude | Parcial aprovado | Middleware `role` por papel (owner/professional/customer) aplicado nas rotas; login opcional para profissional e cliente (gap que bloqueava o Flutter); seeds de demonstracao com os 3 papeis e dados espelhando os mocks do app; 10 novos testes cobrindo inadimplencia, limite de uso, restricao de dia/horario e autorizacao por papel (15/15 passando); `docs/api.md` com contrato de payloads | Falta validacao com estabelecimento piloto real; integracao ainda nao foi feita no lado Flutter (app mobile continua mockado) |
| 2026-07-03 | Claude | Parcial aprovado | Liberada leitura de servicos/profissionais para `customer` (filtrada a ativos); novos endpoints de auto-perfil `GET/PATCH /me/professional` e `GET /me/client` (cada um checando o proprio papel internamente, sem depender do middleware `role`); `GET /appointments` passou a auto-escopar pelo profissional logado; `PhaseZeroSelfServiceTest.php` (5 testes novos, 20/20 no total) cobre leitura de catalogo por cliente, isolamento de `/me/*` por papel e escopo de agenda; `docs/api.md` atualizado; validado ponta a ponta contra o app Flutter real | Servicos habilitados por profissional e profissionais habilitados por plano continuam sem modelagem; validacao com estabelecimento piloto real ainda pendente |
| 2026-07-04 | Codex | Em auditoria | Rechecagem confirmou implementacao real de servicos por profissional, profissionais por plano, agendamento avulso e fila de espera; `php artisan test` passou com 48/48 testes; `php artisan route:list --path=api` listou 41 rotas esperadas | Validacao com estabelecimento piloto real segue pendente; roadmap historico preserva linhas antigas como contexto |
| 2026-07-08 | Claude | Aprovado | Usuario reportou bug real testando onboarding: donos de salao de profissional unico (o proprio dono corta cabelo/faz barba) nao conseguiam se cadastrar como profissional porque `ProfessionalController::store` exigia e-mail unico em `users` quando uma senha de acesso era informada, e o e-mail informado colidia com o proprio login do dono (`users.email` e unico globalmente, sem `->ignore()`). Decisao confirmada com o usuario: nao criar suporte a multi-papel por `User` agora (mudanca estrutural maior); o dono continua gerenciando o proprio perfil de profissional pela conta de owner, sem login separado. Correcao aplicada: quando `password` e informado e o e-mail bate com o do usuario autenticado, a API retorna 422 com mensagem especifica explicando que aquele e-mail ja e o login do proprietario e que deve ficar em branco para nao criar login separado, em vez do erro generico de e-mail duplicado | Validado manualmente pelo usuario no emulador; suporte a multi-papel de fato (mesmo login acessando telas de dono e profissional) fica como debito conhecido caso vire necessidade real |
| 2026-07-08 | Claude | Aprovado | Usuario pediu que o cliente enxergasse a agenda do salao inteiro (todos os profissionais/clientes), nao so os proprios agendamentos, para se programar antes de decidir entre agendar direto ou entrar na fila de espera. Investigado antes de codar: `GET /appointments` (`AppointmentController::index`) serializa `client`/`professional` crus via `with()`, sem `$hidden`/Resource — simplesmente remover o filtro `client_id` para `customer` vazaria e-mail/telefone/data de nascimento/observacoes de outros clientes e e-mail/telefone/`commission_percentage` dos profissionais. Confirmado com o usuario: mostrar so horario ocupado, sem nome de cliente. Criado `AppointmentController::salonSchedule()` (rota nova `GET /appointments/salon`, mesmo grupo `role:owner,professional,customer` de `GET /appointments`) que exclui `status=canceled`, nunca carrega `client` e so carrega `professional:id,name` e `service:id,name,price_cents` (sem `notes`/`cancellation_reason`/campos sensiveis do profissional). 2 testes novos em `PhaseZeroSalonScheduleTest` confirmando ausencia de `client`/e-mail/telefone/comissao na resposta e exclusao de cancelados (83/83 testes de backend passando) | Sem validacao ponta a ponta em ambiente real ainda |
| 2026-07-08 | Claude | Aprovado | Bug real reportado pelo usuario testando o app: cliente escolhia servico e profissional, mas ao chegar na tela de horario recebia "acesso nao autorizado" e nao conseguia agendar. Causa: `ChooseTimePage` (app) sempre chamou `GET /tenant/schedule-overrides` pra saber excecoes de horario (fechado/horario diferente numa data especifica) e montar os slots disponiveis, mas essa rota estava dentro do grupo `role:owner` desde que a feature de horario de funcionamento foi criada — nunca liberada para `professional`/`customer`, apesar de os dois precisarem ler essa informacao pra agendar. Nao e um bug introduzido nesta sessao (pre-existia desde a feature de horario de funcionamento), so nunca tinha sido percebido porque a validacao anterior desse fluxo usou um tenant sem excecao configurada nem checou o retorno em detalhe. Corrigido movendo so a leitura (`GET /tenant/schedule-overrides`) pro grupo `role:owner,professional,customer` (mesmo grupo de `GET /appointments`/`GET /appointments/salon`) — a escrita (`POST`/`DELETE`) continua exclusiva do dono. O controller so devolve `date`/`is_closed`/`opens_at`/`closes_at`, nada sensivel. Teste `test_professional_and_customer_cannot_manage_schedule_overrides` (que antes esperava 403 tambem na leitura) foi reescrito para `test_professional_and_customer_cannot_write_schedule_overrides_but_can_read`, cobrindo que cliente le mas nao cria/apaga (83/83 testes de backend passando). `php artisan route:clear` executado pra garantir que a rota nova nao ficasse presa em cache local | Validado manualmente pelo usuario apos a correcao |
| 2026-07-08 | Claude | Aprovado | Usuario pediu que o cliente pudesse ver os proprios dados e alterar nome, telefone, e-mail e senha pelo botao "Perfil" — hoje essa tela so mostrava dados da assinatura, sem nenhum dado pessoal nem opcao de edicao. Investigado antes de codar: `Client` (dados de contato) e `User` (login) sao registros independentes, sincronizados so na criacao, nunca depois — mesmo padrao ja usado por `Professional`/`User`. `PATCH /me/credentials` (`AuthController::updateCredentials`) ja funcionava pra qualquer papel (customer incluso), so nunca tinha tela que o chamasse pelo lado do cliente. Faltava so autoedicao dos dados de contato: `ClientController::updateSelf()` (rota nova `PATCH /me/client`) segue exatamente o padrao de `ProfessionalController::updateSelf`/`PATCH /me/professional` — valida `name`/`phone` (unico por tenant, ignorando o proprio registro)/`email`, atualiza so o `Client` do usuario autenticado. 1 teste novo em `PhaseZeroSelfServiceTest` cobrindo edicao propria, telefone duplicado rejeitado (422) e acesso negado para outros papeis (84/84 testes de backend passando) | Sem validacao ponta a ponta em ambiente real ainda |

## Fase 1 - Planos SaaS e Controle de Acesso

Status: `Em auditoria`

Objetivo: construir a infraestrutura de planos SaaS descrita na especificacao (secao 3) — trial de 30 dias e os 3 tiers pagos (Basico/Intermediario/Premium), com limites e liberacao de funcionalidade por plano. Hoje so existe uma tabela `saas_subscriptions` esqueleto (`plan_name` fixo "Plano Fundador", sem tiers nem limites), entao nenhuma acao no sistema e realmente restrita por plano.

### Escopo previsto

- [x] Schema de limites por tier (profissionais, clientes assinantes ativos, unidades) via `saas_plans`
- [x] Modelagem dos 4 tiers (Trial, Basico R$79,99, Intermediario R$129,99, Premium R$199,99) com preco e limites
- [x] `PlanGate` centralizado checando limite antes de acoes restritas
- [x] `POST /auth/register-owner` passa a criar o tenant ja em trial de 30 dias, sem cartao
- [x] Endpoint de upgrade/downgrade de plano SaaS
- [x] Regra de downgrade (spec 3.5): registros excedentes ficam inativos, nunca sao removidos
- [ ] Suporte a multiplas unidades/filiais por tenant (exclusivo do tier Premium) — nesta fase so existe `tenants.units_count`, sem CRUD operacional de unidades
- [x] Checagem de expiracao de trial calculada em leitura/escrita, sem job agendado nesta fase

### Criterios de aceite

- [x] Tenant criado via `/auth/register-owner` nasce em trial com `trial_ends_at` em +30 dias
- [x] Acao bloqueada por `PlanGate` retorna erro especifico (nao um 403 generico)
- [x] Downgrade testado nao remove registros excedentes, so inativa
- [x] Testes automatizados cobrindo limite por tier e downgrade

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-03 | Claude | Nao iniciado | Auditoria da especificacao vs. roadmap encontrou a lacuna: so existe `app/Models/SaasSubscription.php` com `plan_name` fixo, sem os 4 tiers nem tabela de limites | Toda a fase — schema `plan_features`, `PlanGate`, trial automatico, upgrade/downgrade |
| 2026-07-04 | Codex | Em auditoria | Rechecagem encontrou `saas_plans`, vinculo `saas_subscriptions.saas_plan_id`, `PlanGate`, rotas `GET /saas-plans` e `PATCH /saas-subscription`, bloqueio 402 para trial vencido e testes dedicados em `PhaseUmSaasPlansTest`; `php artisan test` passou com 48/48 testes | Multi-unidade ainda e limite numerico, sem CRUD; gateway de pagamento permanece para fase futura |

## Fase 2 - Cobranca Manual Operacional

Status: `Em andamento`

Objetivo: profissionalizar a cobranca manual da primeira versao: o dono confirma recebimentos pelo app, escolhe a modalidade usada e consegue manter cobrancas em aberto como fiado.

### Escopo previsto

- [x] Confirmacao manual de pagamento pelo proprietario
- [x] Modalidades manuais: `pix`, `credit_card`, `debit_card`, `cash`
- [x] Modalidade `fiado`, mantendo o pagamento pendente
- [x] Lancamentos parciais de recebimento para quitar fiado aos poucos
- [x] Atualizacao da assinatura quando o pagamento e quitado
- [x] Relatorio/lista separada de valores fiados
- [x] Extrato de comissao do profissional por semana/mes
- [x] Gestao de adiantamentos ao profissional
- [x] Configuracao de dia de pagamento dos profissionais
- [ ] Disparo de notificacao push (FCM) para confirmacao de agendamento e lembrete de vencimento (spec 3.2/4.3, tier Basico) — job assincrono, guarda o token de aparelho por usuario

### Criterios de aceite

- [x] Proprietario escolhe modalidade antes de confirmar pagamento
- [x] Pagamento confirmado atualiza assinatura corretamente
- [x] Fiado nao marca como pago e continua pendente
- [x] Testes automatizados cobrindo todas as modalidades manuais
- [x] Testes automatizados cobrindo recebimento parcial de fiado
- [x] Testes automatizados cobrindo extrato de comissao e adiantamento

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-04 | Codex | Em andamento | Escopo corrigido a pedido do usuario: primeira versao nao integra gateway; pagamento e manual pelo dono. Backend passa a exigir modalidade em `POST /payments/{id}/mark-paid`, aceitando `pix`, `credit_card`, `debit_card`, `cash` e `fiado`; `fiado` registra a modalidade mas mantem `status=pending` e nao preenche `paid_at` | Falta cobrir todas as modalidades em testes e criar visao operacional dedicada para fiados |
| 2026-07-04 | Codex | Parcial aprovado | Fiado ganhou recebimentos parciais em `payment_receipts` e endpoint `POST /payments/{id}/receipts`; cliente ganhou `GET /me/payments`; profissional ganhou extrato `GET /me/professional/finance`; dono ganhou consulta de extrato por profissional, lancamento de adiantamento e configuracao de `professional_payment_day`; `php artisan test` passou com 52/52 testes | Falta notificacao push FCM e validacao em dispositivo real |
| 2026-07-07 | Claude | Parcial aprovado | Usuario pediu que o profissional visse, no proprio painel, os rendimentos em valores realizados no mes e quantos atendimentos fez (avulso vs. plano). `ProfessionalFinanceController::statement()` ganhou `avulso_count`/`plano_count` (contagem de atendimentos concluidos no periodo com/sem `client_subscription_id`) e `avulso_revenue_cents`/`plano_revenue_cents` (mesmo `service.price_cents` ja usado no `gross_cents`, separado por origem) — 1 teste novo em `PhaseDoisProfessionalFinanceTest` cobrindo o split (81/81 testes de backend passando). Consumido pelo app na tela inicial do profissional (roadmap mobile, Fase 4) | Sem validacao ponta a ponta em ambiente real ainda |

## Fase 3 - Onboarding e Autocadastro

Status: `Em andamento`

Objetivo: permitir que o cliente se cadastre sozinho no app, vinculado a um estabelecimento por convite (codigo/link/QR) ou por escolha em um diretorio publico de estabelecimentos ativos, sem depender do dono cadastra-lo manualmente via `POST /clients`. Hoje nao existe nenhuma rota publica de autocadastro de cliente nem qualquer mecanismo de convite/token/QR no backend.

### Escopo previsto

- [x] Campo `invite_code` unico e regeneravel em `tenants` (codigo curto alfanumerico), gerado automaticamente em `POST /auth/register-owner`
- [x] Endpoint publico `GET /tenants/by-invite-code/{code}` retornando dados minimos do salao (nome, tipo de negocio, cidade) para a tela de confirmacao do convite
- [x] Endpoint publico `GET /tenants/directory` listando estabelecimentos ativos (nome, cidade, tipo de negocio) para o cliente avulso escolher sem convite
- [x] Endpoint publico `POST /auth/register-client` (sem autenticacao previa), recebendo `invite_code` OU `tenant_id` (do diretorio) + dados do cliente (nome, telefone, e-mail, senha), criando `Client` + `User(role:customer)` ja ativos e autenticados na mesma resposta (mesmo padrao transacional do `register-owner`)
- [x] Endpoint para o dono regenerar o `invite_code` do proprio tenant, invalidando o anterior
- [x] Testes cobrindo: cadastro via codigo de convite valido, codigo invalido, cadastro via diretorio, duplicidade de telefone por tenant, e isolamento do diretorio (sem vazar dado financeiro/sensivel do estabelecimento)
- [ ] Telas do app mobile (escolha de perfil, deep link/QR, diretorio, cadastro do cliente, compartilhar/regenerar convite, checklist do dono) — proxima etapa

### Criterios de aceite

- [x] Cliente consegue se autocadastrar informando um codigo de convite valido, sem login previo
- [x] Cliente consegue se autocadastrar escolhendo um salao no diretorio publico
- [x] Cadastro via convite/diretorio ja entra ativo, sem aprovacao manual do dono
- [x] Diretorio publico nao expoe dado financeiro/sensivel do estabelecimento
- [x] Dono consegue regenerar o codigo de convite do proprio salao

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-05 | Claude | Parcial aprovado | Migration adiciona `invite_code` a `tenants` (unico, 6 caracteres sem 0/O/1/I/L para evitar confusao em tela pequena/impresso) com backfill dos tenants ja existentes; `Tenant::booted()` gera o codigo sozinho em qualquer criacao (`register-owner` nao precisou mudar); `TenantController` ganhou `byInviteCode`, `directory` (ambos publicos, so `id/name/business_type/city`) e `regenerateInviteCode` (exclusivo do dono); `AuthController::registerClient` (publico) aceita `invite_code` OU `tenant_id`, valida telefone unico por tenant (mesma regra do `ClientController::store`) e cria `User(customer)` + `Client` numa transacao, retornando token pronto pro app logar direto — mesmo padrao do `register-owner`. 7 testes novos em `PhaseTresOnboardingTest` (59/59 testes de backend passando); `docs/api.md` atualizado. Validado ponta a ponta contra a API real (nao mockada) rodando em `php artisan serve`: diretorio publico listou os 4 tenants existentes com `invite_code` ja preenchido pelo backfill; consulta por codigo de convite (inclusive em minusculo) resolveu o tenant certo; cliente se autocadastrou via convite, recebeu token, e o token funcionou em `GET /me/client`; dono logado regenerou o proprio codigo e o codigo antigo passou a retornar 404 enquanto o novo funcionou | Mobile (todas as telas) ainda nao existe — proxima etapa desta fase |
| 2026-07-05 | Claude | Parcial aprovado | Correcao reportada pelo usuario: mensagens de validacao (`422`) chegavam ao app em ingles (ex: "The client.phone has already been taken."). Nenhum controller da API personalizava mensagens e nao havia arquivo de traducao no projeto. Criado `lang/pt_BR/validation.php` cobrindo as regras usadas na API (required, email, unique, min, max, integer, exists, in, etc.) com nomes de campo amigaveis (`attributes`) para os principais formularios (`client.*`, `owner.*`, `tenant.*`, telefone, e-mail, senha...); `APP_LOCALE` mudou de `en` para `pt_BR` em `.env`/`.env.example` (fallback continua `en`, para regra sem traducao nao quebrar). Nao exige mudanca em nenhum controller: o Laravel resolve a traducao automaticamente pelo locale ativo, entao todo endpoint da API passou a responder em portugues, nao so `register-client`. `php artisan test` continua 59/59 (nenhum teste checa texto exato da mensagem de validacao); validado com `curl` direto na API real: telefone duplicado retornou "Este telefone ja esta cadastrado." e e-mail duplicado "Este e-mail ja esta cadastrado." | Nenhuma pendencia conhecida |
| 2026-07-05 | Claude | Aprovado | Adicionado `PATCH /me/credentials`, a pedido do usuario ao notar que o dono nao tinha como trocar o proprio e-mail/senha: exige `current_password` (via `Hash::check`) antes de aplicar `email`/`password` novos, exige pelo menos um dos dois, e valida e-mail unico ignorando o proprio usuario. Vale para qualquer papel (owner/professional/customer), nao so dono — e o `User.email`/`password` de login, independente do e-mail de contato guardado em `Client`/`Professional`. Adicionado tambem a lista de excecoes do `EnsureTenantPlanIsActive` (junto com `/auth/logout` e `/saas-subscription`), senao um dono com trial vencido ficaria sem conseguir nem corrigir a propria senha. 6 testes novos em `AccountCredentialsTest` (65/65 testes de backend passando); `docs/api.md` atualizado. Validado direto na API real: senha errada retornou "Senha atual incorreta."; senha certa trocou e o login com a senha antiga passou a falhar; endpoint segue liberado mesmo com o trial forcado a vencido | Nenhuma pendencia conhecida |

### Decisoes

| Data | Decisao | Motivo |
|---|---|---|
| 2026-07-05 | Convite do dono ao cliente usa codigo fixo regeneravel, nao um token unico por convite | Simplicidade para dono leigo em tecnologia: reutiliza o mesmo codigo/QR sem precisar gerar um novo a cada pessoa convidada; confirmado com o usuario |
| 2026-07-05 | Cliente avulso sem convite escolhe o salao em um diretorio publico (nome, cidade, tipo de negocio) | Reduz friccao de cadastro para quem nao recebeu convite de ninguem; aceito o tradeoff de expor a existencia de estabelecimentos concorrentes entre si na mesma cidade |
| 2026-07-05 | Cadastro de cliente via convite/diretorio entra ativo direto, sem aprovacao manual do dono | Confirmacao de pagamento ja e manual e feita separadamente pelo dono; bloquear o cadastro em si adicionaria friccao sem reduzir risco financeiro real |

## Fase 4 - Painel Inteligente do Proprietario

Status: `Em auditoria`

Objetivo: expor agregacoes prontas para o dashboard do dono ver o dia em 5 segundos (agendamentos, receita) e duas ferramentas de gestao proativa (ocupacao da equipe, priorizacao de retorno de clientes), sem o app precisar computar isso client-side a partir de listas inteiras.

### Escopo previsto

- [x] `GET /dashboard/summary`: contagens de hoje (agendamentos, confirmados, pendentes, cancelamentos, fila de espera) e receita (prevista hoje, recorrente do mes, avulsa do mes)
- [x] Horario de trabalho do profissional por dia da semana (nova tabela `professional_working_hours`), aceito em `POST/PATCH /professionals`
- [x] `GET /dashboard/occupancy`: percentual ocupado por profissional/dia da semana (semana corrente), a partir do horario de trabalho cadastrado
- [x] `GET /dashboard/return-risk`: para clientes com 2+ atendimentos concluidos, dias desde o ultimo atendimento vs. media historica propria, com probabilidade de retorno (heuristica)
- [x] Ajuste pontual do horario do profissional por data (nova tabela `professional_schedule_overrides`), gerenciado pelo proprio profissional via `/me/professional/schedule-overrides`, afetando o calculo de ocupacao daquele dia especifico

### Criterios de aceite

- [x] `GET /dashboard/summary` reflete corretamente pagamento avulso pendente/pago, cancelamento e fila de espera
- [x] Horario de trabalho e sincronizado (delete+recreate) sem apagar quando a chave nao e enviada num update parcial, mesmo padrao ja usado por `service_ids`
- [x] `GET /dashboard/occupancy` calcula ocupacao so para dias com horario configurado, capada em 100%
- [x] `GET /dashboard/return-risk` exclui clientes com menos de 2 atendimentos concluidos e ordena por quem esta mais "devendo" retornar
- [x] Profissional cria/le/apaga o proprio ajuste de horario por data, sem conseguir mexer no ajuste de outro colega
- [x] `GET /dashboard/occupancy` usa o ajuste pontual (inclusive "folga") em vez do horario recorrente quando ha um ajuste para a data

### Decisoes

| Data | Decisao | Motivo |
|---|---|---|
| 2026-07-07 | Criar horario de trabalho individual por profissional (`professional_working_hours`), em vez de usar o horario do salao para todos | So existia horario de funcionamento no nivel tenant; ocupacao por profissional exige capacidade individual. Confirmado com o usuario antes de implementar |
| 2026-07-07 | "Pendente" no resumo do dia = agendamento com `Payment` avulso `status=pending`; "confirmado" = restante dos agendamentos ativos do dia | Agendamento nao tem status "confirmado" separado (`scheduled`/`canceled`/`completed`/`no_show`); semantica definida com o usuario em vez de criar um status novo so para o card |
| 2026-07-07 | Probabilidade de retorno: razao `dias_desde_ultimo / media_historica` — <0,85 baixa, 0,85-1,6 alta, >1,6 media | Faixas calibradas pelo exemplo do proprio usuario (38 dias, media 25 -> deveria ser "alta"); heuristica simples, IA de verdade continua na Fase 9 |
| 2026-07-07 | Ajuste pontual de horario (`professional_schedule_overrides`) e tabela separada do horario recorrente, uma linha por profissional+data | Preserva o horario fixo intacto; o profissional so registra o desvio pontual (inclusive "nao vou trabalhar"), sem apagar/recriar o cadastro normal |

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-07 | Claude | Parcial aprovado | Nova migration `professional_working_hours` (unique por profissional+dia da semana); `ProfessionalController::store/update` sincroniza via delete+recreate (mesmo padrao de `service_ids`), com validacao de dia duplicado e fim antes do inicio. Novo `OwnerDashboardController` com `summary()` (usa `Appointment.payment` para distinguir confirmado/pendente, `WaitlistEntry.status=waiting` para a fila, soma de `ClientSubscription` ativas para receita recorrente e `Payment` avulso pago no mes para receita avulsa), `occupancy()` (minutos ocupados/disponiveis por profissional/dia na semana corrente) e `returnRisk()` (media de intervalo entre atendimentos concluidos vs. dias desde o ultimo). 4 testes novos em `PhaseQuatroOwnerDashboardTest` (81/81 testes de backend passando) | Sem validacao ponta a ponta em ambiente real ainda — usuario optou por validar manualmente esta rodada |
| 2026-07-07 | Claude | Parcial aprovado | Usuario pediu que o profissional pudesse ajustar o proprio horario num dia especifico (chegou mais tarde, etc.), refletindo na ocupacao. Nova migration `professional_schedule_overrides` (`professional_id`+`date` unico, `is_off` para folga, `starts_at`/`ends_at` para o horario daquele dia); novo `ProfessionalScheduleOverrideController` (`me`/`storeMe` com `updateOrCreate` por data/`destroyMe`), exclusivo do proprio profissional logado (nunca mexe no ajuste de outro colega — coberto por teste). `OwnerDashboardController::occupancy()` foi reescrito para iterar as 7 datas reais da semana corrente (nao so o `weekday`) e, pra cada data, checar primeiro se ha ajuste pontual: se `is_off`, o dia some da resposta; se houver horario, usa o do ajuste; senao cai no horario recorrente (`professional_working_hours`) — resposta ganhou `date` e `has_override` por dia. 3 testes novos em `PhaseQuatroOwnerDashboardTest` (80/80 testes de backend passando: CRUD do ajuste com isolamento entre profissionais, ocupacao usando o ajuste em vez do recorrente, e o dia sumindo quando marcado como folga) | Sem validacao ponta a ponta em ambiente real ainda |

## Fase 5 - Fidelidade e Avaliacoes

Status: `Nao iniciado`

### Escopo previsto

- [ ] Avaliacao pos-atendimento
- [ ] Pontos por uso/renovacao
- [ ] Niveis Bronze, Silver, Gold e Black
- [ ] Extrato de pontos

## Fase 6 - CRM Avancado e Estoque

Status: `Nao iniciado`

### Escopo previsto

- [ ] Historico ampliado do cliente
- [ ] Preferencias e profissional favorito
- [ ] Cliente inativo
- [ ] Produtos e estoque
- [ ] Vendas de produtos

## Fase 7 - Marketing Automation

Status: `Nao iniciado`

### Escopo previsto

- [ ] Campanhas de aniversario
- [ ] Recuperacao de cliente inativo
- [ ] Recuperacao de cancelamento
- [ ] Cupons e indicacoes

## Fase 8 - Business Intelligence

Status: `Nao iniciado`

### Escopo previsto

- [ ] MRR
- [ ] Churn
- [ ] LTV
- [ ] Ticket medio
- [ ] Ocupacao de agenda
- [ ] Ranking de profissionais

## Fase 9 - Inteligencia Artificial

Status: `Nao iniciado`

### Escopo previsto

- [ ] Assistente de agendamento
- [ ] Sugestao de campanhas
- [ ] Previsao de churn
- [ ] Recomendacao de servicos/produtos

## Decisoes

| Data | Decisao | Motivo | Impacto |
|---|---|---|---|
| 2026-07-03 | Comecar com cobranca manual | Validar negocio antes de integrar Asaas | Menor complexidade na Fase 0 |
| 2026-07-03 | Manter portal web fora do lancamento | App mobile e principal no PRD | Reduz superficie de desenvolvimento |
| 2026-07-03 | Remover a fase "Portal Web Administrativo"; mover "Multi-unidade" para a nova Fase 1 | A especificacao (secoes 1 e 6) define "zero painel web administrativo" como decisao de produto permanente, nao como item so fora do lancamento inicial — "Relatorios avancados" ja e coberto pela Fase 6 (Business Intelligence); "Multi-unidade" e recurso do tier Premium do SaaS, nao depende de painel web | Fase 7 (Inteligencia Artificial) mantem o mesmo numero; nenhuma outra fase referenciava o Portal Web |
| 2026-07-03 | Inserir a Fase 1 "Planos SaaS e Controle de Acesso" | Trial + 3 tiers pagos + `PlanGate` (secao 3 da especificacao) e o nucleo do modelo de negocio e nao tinha nenhuma fase no roadmap | Fases antigas 1-5 foram renumeradas para 2-6; Fase 7 nao muda |
| 2026-07-03 | Adicionar disparo de notificacao push (FCM) na Fase 2 | Item nunca tinha sido listado no roadmap do backend, apesar de a especificacao inclui-lo ja no tier Basico (3.2/4.3); mesma decisao tomada no roadmap do mobile — push so faz sentido completo junto com o resto da cobranca/lembrete, nao na fundacao | Fase 2 passa a cobrir lembretes ligados a cobranca manual e agendamentos |
| 2026-07-05 | Inserir a Fase 3 "Onboarding e Autocadastro", a pedido do usuario apos revisao de usabilidade | Hoje o cliente so entra no sistema se o dono cadastrar manualmente via `POST /clients`, e nao existe nenhum mecanismo de convite/token/QR nem rota publica de autocadastro — isso nao atende a expectativa de cliente se autocadastrar via convite/link/QR ou de forma avulsa escolhendo o salao | Fases antigas 3-7 (Fidelidade, CRM, Marketing, BI, IA) foram renumeradas para 4-8 |
| 2026-07-05 | Trocar `DB_CONNECTION` de sqlite para MySQL (banco local `clube_do_salao` no MySQL do XAMPP), a pedido do usuario | Ate aqui o projeto todo (Fases 0-3) rodava contra um arquivo SQLite local; a especificacao (secao 5) sempre previu MySQL/PostgreSQL para producao. SQLite nao forca integridade referencial por padrao, o que escondeu 3 bugs reais de migration que so aparecem em MySQL: (1) `subscription_usages` tinha FK para `appointments` mas rodava antes da tabela existir — migration renomeada de `..._142245_...` para `..._142249_...` pra rodar depois; (2) colunas `timestamp()` obrigatorias sem default (`appointments.starts_at/ends_at`, `payment_receipts.received_at`, `professional_advances.paid_at`, `subscription_usages.used_at`) violam o modo estrito do MySQL (`Invalid default value`) — trocadas para `dateTime()`; (3) nome de indice unico de `professional_subscription_plan` passava de 64 caracteres (limite do MySQL) — index nomeado explicitamente. Tambem corrigido `DatabaseSeeder`: usa `WithoutModelEvents`, entao o hook `Tenant::booted()` que gera o `invite_code` sozinho nao disparava — tenant de demonstracao ficava com `invite_code` nulo; seeder agora gera o codigo explicitamente | `php artisan test` (sqlite `:memory:` via `phpunit.xml`) continua 59/59 sem mudanca; `migrate:fresh --seed` e validacao via curl (diretorio, login, autocadastro por convite) confirmados contra o MySQL real |
| 2026-07-07 | Inserir a Fase 4 "Painel Inteligente do Proprietario", a pedido do usuario | Nenhuma fase cobria agregacoes de dashboard nem ocupacao/retorno de clientes; o app hoje computava metricas client-side buscando listas inteiras, sem contagens de confirmado/pendente/cancelamento/fila que o usuario pediu | Fases antigas 4-8 (Fidelidade, CRM, Marketing, BI, IA) foram renumeradas para 5-9 |
