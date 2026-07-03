# Clube do Salao API

API headless em Laravel 12 para a Fase 0 do Clube do Salao.

## Incluido nesta base

- Autenticacao mobile com Laravel Sanctum.
- Onboarding de estabelecimento e proprietario.
- Multi-tenant por `tenant_id`.
- Cadastros de profissionais, clientes e servicos.
- Planos de assinatura com servicos inclusos, limite de uso, dias e horarios permitidos.
- Assinaturas de clientes com status de pagamento manual.
- Agenda com verificacao de conflito por profissional.
- Conclusao de atendimento com registro de uso da assinatura.
- Pagamentos manuais por PIX, dinheiro, cartao ou outro metodo.

## Comandos

```powershell
composer install
php artisan migrate:fresh --force
php artisan serve
```

```powershell
php artisan route:list --path=api
php artisan test
vendor\bin\pint
```
