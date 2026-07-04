<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_subscriptions', function (Blueprint $table) {
            $table->foreignId('saas_plan_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        // Assinaturas ja existentes sao todas trial (nenhum tier pago foi lancado ainda).
        $trialPlanId = DB::table('saas_plans')->where('code', 'trial')->value('id');
        DB::table('saas_subscriptions')->whereNull('saas_plan_id')->update(['saas_plan_id' => $trialPlanId]);
    }

    public function down(): void
    {
        Schema::table('saas_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saas_plan_id');
        });
    }
};
