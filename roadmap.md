# Roadmap de Desenvolvimento - Backend

Este documento guia e audita a evolucao da API do Clube do Salao. Toda fase deve ser marcada aqui com status, escopo entregue, testes executados, pendencias e decisao de continuidade.

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
- [ ] Regras de acesso por papel em middleware/policies
- [ ] Seeds de demonstracao
- [ ] Cobertura de testes para inadimplencia, limite de uso e restricoes de horario
- [ ] Documentacao de payloads da API

### Criterios de aceite

- [x] `php artisan migrate:fresh --force` executa sem erros
- [x] `php artisan test` executa sem erros
- [x] `php artisan route:list --path=api` lista rotas esperadas
- [x] Teste automatizado cobre rollback em falha de plano
- [x] Teste automatizado cobre erro JSON padronizado
- [ ] Fluxo completo testado via cliente HTTP
- [ ] Validacao funcional com ao menos 1 estabelecimento piloto

### Auditoria da fase

| Data | Responsavel | Resultado | Evidencias | Pendencias |
|---|---|---|---|---|
| 2026-07-03 | Codex | Parcial aprovado | Migracoes, rotas e teste de fluxo principal passaram | Regras por papel, seeds, docs de payload e testes adicionais |
| 2026-07-03 | Codex | Parcial aprovado | Comentarios em PT-BR, handler global de excecoes, transacoes explicitas e testes de rollback/JSON | Ainda faltam policies por papel e testes de regras negativas especificas |

## Fase 1 - Cobranca Automatica e Base Operacional

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

### Criterios de aceite

- [ ] Webhook idempotente
- [ ] Pagamento confirmado atualiza assinatura corretamente
- [ ] Pagamento atrasado bloqueia uso conforme regra
- [ ] Testes automatizados cobrindo fluxo feliz e duplicidade de webhook

## Fase 2 - Fidelidade e Avaliacoes

Status: `Nao iniciado`

### Escopo previsto

- [ ] Avaliacao pos-atendimento
- [ ] Pontos por uso/renovacao
- [ ] Niveis Bronze, Silver, Gold e Black
- [ ] Extrato de pontos

## Fase 3 - CRM Avancado e Estoque

Status: `Nao iniciado`

### Escopo previsto

- [ ] Historico ampliado do cliente
- [ ] Preferencias e profissional favorito
- [ ] Cliente inativo
- [ ] Produtos e estoque
- [ ] Vendas de produtos

## Fase 4 - Marketing Automation

Status: `Nao iniciado`

### Escopo previsto

- [ ] Campanhas de aniversario
- [ ] Recuperacao de cliente inativo
- [ ] Recuperacao de cancelamento
- [ ] Cupons e indicacoes

## Fase 5 - Business Intelligence

Status: `Nao iniciado`

### Escopo previsto

- [ ] MRR
- [ ] Churn
- [ ] LTV
- [ ] Ticket medio
- [ ] Ocupacao de agenda
- [ ] Ranking de profissionais

## Fase 6 - Portal Web Administrativo

Status: `Nao iniciado`

### Escopo previsto

- [ ] Portal Laravel/Livewire
- [ ] Relatorios avancados
- [ ] Multi-unidade
- [ ] Gestao operacional em tela grande

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
