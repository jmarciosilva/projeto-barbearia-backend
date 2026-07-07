<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajuste pontual do horario de trabalho do profissional para uma data
     * especifica (ex: chegou mais tarde hoje, ou nao vai trabalhar hoje),
     * sem alterar o horario recorrente cadastrado em
     * `professional_working_hours`. Quando nao ha ajuste para a data, vale
     * o horario recorrente do dia da semana.
     */
    public function up(): void
    {
        Schema::create('professional_schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_off')->default(false);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['professional_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_schedule_overrides');
    }
};
