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
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Nulo = "qualquer profissional"; quando preenchido, e uma preferencia do
            // cliente que o staff usa ao atribuir o horario em assign().
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('waiting')->index();
            $table->text('notes')->nullable();
            // Preenchido quando o staff atribui um horario e a entrada vira agendamento de verdade.
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
