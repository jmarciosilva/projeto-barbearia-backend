<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horario de funcionamento padrao do salao e uma pausa opcional (ex:
     * almoco). Tudo nullable: tenant sem nada configurado mantem o
     * comportamento atual, sem nenhuma restricao de horario.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->time('opening_time')->nullable()->after('professional_payment_day');
            $table->time('closing_time')->nullable()->after('opening_time');
            $table->time('break_start_time')->nullable()->after('closing_time');
            $table->time('break_end_time')->nullable()->after('break_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['opening_time', 'closing_time', 'break_start_time', 'break_end_time']);
        });
    }
};
