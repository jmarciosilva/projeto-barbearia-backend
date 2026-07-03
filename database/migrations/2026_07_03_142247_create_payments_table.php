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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('method')->default('pix');
            $table->string('status')->default('pending')->index();
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_on']);
        });
    }

    /**
     * Reverte as migracoes.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
