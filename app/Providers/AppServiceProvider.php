<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicos da aplicacao.
     */
    public function register(): void
    {
        // Nenhum servico customizado registrado nesta fase.
    }

    /**
     * Inicializa servicos da aplicacao.
     */
    public function boot(): void
    {
        // Espaco reservado para configuracoes globais futuras.
    }
}
