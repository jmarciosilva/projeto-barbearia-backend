<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Selo "Salao Fundador" (roadmap Fase 5): decidido manualmente pelo
     * administrador da plataforma, sem relacao com o tier de assinatura SaaS
     * do tenant. Alem do selo, suprime o aviso de trial vencendo no app,
     * que nao faz sentido pra quem esta no clube dos fundadores.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_founder')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_founder');
        });
    }
};
