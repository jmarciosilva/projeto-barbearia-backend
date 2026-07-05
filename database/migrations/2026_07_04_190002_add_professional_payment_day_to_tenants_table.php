<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dia do mes em que o salao costuma acertar com os profissionais.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('professional_payment_day')->default(5)->after('units_count');
        });
    }

    /**
     * Remove a configuracao de dia de pagamento.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('professional_payment_day');
        });
    }
};
