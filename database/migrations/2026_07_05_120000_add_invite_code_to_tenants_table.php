<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Executa as migracoes.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invite_code', 8)->nullable()->unique()->after('status');
        });

        // Tenants que ja existiam antes do convite existir tambem precisam de
        // um codigo, senao nao teriam como convidar cliente.
        foreach (DB::table('tenants')->whereNull('invite_code')->pluck('id') as $tenantId) {
            DB::table('tenants')->where('id', $tenantId)->update([
                'invite_code' => $this->generateUniqueCode(),
            ]);
        }
    }

    /**
     * Reverte as migracoes.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invite_code');
        });
    }

    /**
     * Gera um codigo curto, sem caracteres ambiguos (0/O, 1/I/L), unico entre
     * os tenants existentes. Duplicado do `Tenant::generateInviteCode()` do
     * model porque migrations nao devem depender de classes da aplicacao.
     */
    private function generateUniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
            $code = str_replace(['0', 'O', '1', 'I', 'L'], ['2', 'P', '3', 'J', 'K'], $code);
        } while (DB::table('tenants')->where('invite_code', $code)->exists());

        return $code;
    }
};
