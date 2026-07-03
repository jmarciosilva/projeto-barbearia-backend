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

Status: `Em andamento`

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
- [ ] Servicos habilitados por profissional (spec 4.1: pivot profissional/servico, ainda nao modelado)
- [ ] Profissionais habilitados por plano (spec 4.2: restricao de plano, ainda nao modelada)

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

## Fase 1 - Planos SaaS e Controle de Acesso

Status: `Nao iniciado`

Objetivo: construir a infraestrutura de planos SaaS descrita na especificacao (secao 3) — trial de 30 dias e os 3 tiers pagos (Basico/Intermediario/Premium), com limites e liberacao de funcionalidade por plano. Hoje so existe uma tabela `saas_subscriptions` esqueleto (`plan_name` fixo "Plano Fundador", sem tiers nem limites), entao nenhuma acao no sistema e realmente restrita por plano.

### Escopo previsto

- [ ] Schema `plan_features`/limites por tier (profissionais, clientes assinantes ativos, unidades)
- [ ] Modelagem dos 4 tiers (Trial, Basico R$79,99, Intermediario R$129,99, Premium R$199,99) com preco e limites
- [ ] `PlanGate` centralizado (service/middleware) checando feature/limite antes de acoes restritas (cadastro de profissional, servico, unidade etc.)
- [ ] `POST /auth/register-owner` passa a criar o tenant ja em trial de 30 dias, sem cartao
- [ ] Endpoint de upgrade/downgrade de plano SaaS
- [ ] Regra de downgrade (spec 3.5): registros excedentes ficam `is_active=false`, nunca sao removidos
- [ ] Suporte a multiplas unidades/filiais por tenant (exclusivo do tier Premium)
- [ ] Job de checagem de expiracao de trial

### Criterios de aceite

- [ ] Tenant criado via `/auth/register-owner` nasce em trial com `trial_ends_at` em +30 dias
- [ ] Acao bloqueada por `PlanGate` retorna erro especifico (nao um 403 generico)
- [ ] Downgrade testado nao remove registros excedentes, so inativa
- [ ] Testes automatizados cobrindo limite por tier e downgrade

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-03 | Claude | Nao iniciado | Auditoria da especificacao vs. roadmap encontrou a lacuna: so existe `app/Models/SaasSubscription.php` com `plan_name` fixo, sem os 4 tiers nem tabela de limites | Toda a fase — schema `plan_features`, `PlanGate`, trial automatico, upgrade/downgrade |

## Fase 2 - Cobranca Automatica e Base Operacional

Status: `Nao iniciado`

Objetivo: automatizar cobranca recorrente quando o fluxo manual estiver validado.

### Escopo previsto

- [ ] Integracao Asaas
- [ ] Webhooks de pagamento
- [ ] Retry de cobranca
- [ ] Atualizacao automatica de status `paid`, `pending`, `overdue`
- [ ] Jobs para vencimento e bloqueio de assinatura
- [ ] Auditoria de eventos financeiros
- [ ] Redis e filas quando houver volume
- [ ] Disparo de notificacao push (FCM) para confirmacao de agendamento e lembrete de vencimento (spec 3.2/4.3, tier Basico) — job assincrono, guarda o token de aparelho por usuario

### Criterios de aceite

- [ ] Webhook idempotente
- [ ] Pagamento confirmado atualiza assinatura corretamente
- [ ] Pagamento atrasado bloqueia uso conforme regra
- [ ] Testes automatizados cobrindo fluxo feliz e duplicidade de webhook

## Fase 3 - Fidelidade e Avaliacoes

Status: `Nao iniciado`

### Escopo previsto

- [ ] Avaliacao pos-atendimento
- [ ] Pontos por uso/renovacao
- [ ] Niveis Bronze, Silver, Gold e Black
- [ ] Extrato de pontos

## Fase 4 - CRM Avancado e Estoque

Status: `Nao iniciado`

### Escopo previsto

- [ ] Historico ampliado do cliente
- [ ] Preferencias e profissional favorito
- [ ] Cliente inativo
- [ ] Produtos e estoque
- [ ] Vendas de produtos

## Fase 5 - Marketing Automation

Status: `Nao iniciado`

### Escopo previsto

- [ ] Campanhas de aniversario
- [ ] Recuperacao de cliente inativo
- [ ] Recuperacao de cancelamento
- [ ] Cupons e indicacoes

## Fase 6 - Business Intelligence

Status: `Nao iniciado`

### Escopo previsto

- [ ] MRR
- [ ] Churn
- [ ] LTV
- [ ] Ticket medio
- [ ] Ocupacao de agenda
- [ ] Ranking de profissionais

## Fase 7 - Inteligencia Artificial

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
| 2026-07-03 | Adicionar disparo de notificacao push (FCM) na Fase 2 | Item nunca tinha sido listado no roadmap do backend, apesar de a especificacao inclui-lo ja no tier Basico (3.2/4.3); mesma decisao tomada no roadmap do mobile — push so faz sentido completo junto com o resto da automacao de cobranca/lembrete, nao na fundacao | Fase 2 passa a cobrir tanto a cobranca via Asaas quanto o disparo de push que ela dispara |
