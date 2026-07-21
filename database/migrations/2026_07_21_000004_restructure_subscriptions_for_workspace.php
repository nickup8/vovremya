<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->after('user_id')->constrained('workspaces')->cascadeOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('tariff_plan_id')->nullable(false)->change();
            $table->foreign('tariff_plan_id')->references('id')->on('tariff_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('user_id')->after('workspace_id')->constrained('users')->cascadeOnDelete();
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
