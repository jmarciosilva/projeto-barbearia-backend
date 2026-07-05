<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recebimentos parciais de um pagamento pendente/fiado.
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('method');
            $table->dateTime('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    /**
     * Remove os recebimentos parciais.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
