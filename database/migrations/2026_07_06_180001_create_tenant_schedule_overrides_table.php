<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Excecao pontual ao horario padrao do tenant para uma data especifica
     * (ex: fechar mais cedo num dia, ou fechar o dia inteiro). Quando nao ha
     * excecao para a data, vale o horario padrao do tenant.
     */
    public function up(): void
    {
        Schema::create('tenant_schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_schedule_overrides');
    }
};
