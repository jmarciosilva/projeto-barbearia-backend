<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sem CRUD de unidades/filiais ainda (fora do escopo desta fase); o campo
     * so existe para o limite do plano (spec 3) ficar visivel no app.
     * Todo tenant comeca com 1 unidade.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('units_count')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('units_count');
        });
    }
};
