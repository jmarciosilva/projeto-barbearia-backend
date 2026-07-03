<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executa as migracoes.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_type')->default('beauty_salon');
            $table->string('document')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->string('status')->default('trial')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverte as migracoes.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
