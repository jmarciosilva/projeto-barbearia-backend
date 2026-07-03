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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone');
            $table->date('birth_date')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
        });
    }

    /**
     * Reverte as migracoes.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
