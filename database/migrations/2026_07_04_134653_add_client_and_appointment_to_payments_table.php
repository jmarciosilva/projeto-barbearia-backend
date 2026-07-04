<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Permitem vincular um pagamento avulso (sem assinatura) a um cliente e
            // ao agendamento especifico que ele esta pagando.
            $table->foreignId('client_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->after('client_subscription_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('appointment_id');
        });
    }
};
