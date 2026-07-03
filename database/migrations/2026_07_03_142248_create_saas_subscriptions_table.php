<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executa as migracoes.
     */
    public function up(): void
    {
        Schema::create('saas_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plan_name')->default('Plano Fundador');
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('status')->default('trial')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverte as migracoes.
     */
    public function down(): void
    {
        Schema::dropIfExists('saas_subscriptions');
    }
};
