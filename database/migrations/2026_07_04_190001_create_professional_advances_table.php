<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiantamentos pagos ao profissional, abatidos do extrato de comissao.
     */
    public function up(): void
    {
        Schema::create('professional_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'professional_id', 'paid_at']);
        });
    }

    /**
     * Remove os adiantamentos.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_advances');
    }
};
