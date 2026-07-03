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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('billing_period')->default('monthly');
            $table->unsignedSmallInteger('usage_limit')->nullable();
            $table->json('allowed_weekdays')->nullable();
            $table->time('allowed_start_time')->nullable();
            $table->time('allowed_end_time')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('subscription_plan_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('included_quantity')->nullable();
            $table->unsignedTinyInteger('discount_percentage')->default(0);
            $table->timestamps();

            $table->unique(['subscription_plan_id', 'service_id']);
        });
    }

    /**
     * Reverte as migracoes.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_service');
        Schema::dropIfExists('subscription_plans');
    }
};
