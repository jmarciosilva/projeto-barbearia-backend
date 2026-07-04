<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de referencia (nao multi-tenant) com os tiers do SaaS e seus
     * limites, espelhando a especificacao do produto (secao 3). `null` num
     * limite significa "ilimitado".
     */
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('max_professionals')->nullable();
            $table->unsignedInteger('max_client_subscriptions')->nullable();
            $table->unsignedInteger('max_units')->nullable();
            $table->timestamps();
        });

        DB::table('saas_plans')->insert([
            [
                'code' => 'trial',
                'name' => 'Trial (Premium por 30 dias)',
                'price_cents' => 0,
                'max_professionals' => 3,
                'max_client_subscriptions' => 20,
                'max_units' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'basico',
                'name' => 'Basico',
                'price_cents' => 7999,
                'max_professionals' => 3,
                'max_client_subscriptions' => 100,
                'max_units' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'intermediario',
                'name' => 'Intermediario',
                'price_cents' => 12999,
                'max_professionals' => 8,
                'max_client_subscriptions' => 400,
                'max_units' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'premium',
                'name' => 'Premium',
                'price_cents' => 19999,
                'max_professionals' => null,
                'max_client_subscriptions' => null,
                'max_units' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_plans');
    }
};
