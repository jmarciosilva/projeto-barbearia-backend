<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horario de trabalho do profissional por dia da semana (0 = domingo,
     * mesma convencao do Carbon::dayOfWeek ja usada em
     * SubscriptionPlan.allowed_weekdays). Ausencia de linha para um dia
     * significa que o profissional nao trabalha naquele dia.
     */
    public function up(): void
    {
        Schema::create('professional_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['professional_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_working_hours');
    }
};
