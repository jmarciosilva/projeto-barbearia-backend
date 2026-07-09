<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger (so insert) de extensoes gratuitas de assinatura SaaS concedidas
     * por um administrador da plataforma (ex: negociacao com salao fundador).
     * Guarda quem concedeu, quanto tempo e o preco do plano no momento da
     * concessao (mesmo a assinatura ficando de graca), ja que nao existe
     * gateway de pagamento pra reconstruir esse historico depois.
     */
    public function up(): void
    {
        Schema::create('admin_subscription_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('saas_plan_id')->constrained();
            $table->unsignedSmallInteger('months_added');
            $table->dateTime('previous_current_period_ends_at')->nullable();
            $table->dateTime('new_current_period_ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_subscription_grants');
    }
};
