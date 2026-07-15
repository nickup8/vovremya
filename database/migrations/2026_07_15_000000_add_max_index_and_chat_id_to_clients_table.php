<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index('max_id', 'clients_max_id_idx');
            $table->string('max_chat_id')->nullable()->after('max_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_max_id_idx');
            $table->dropColumn('max_chat_id');
        });
    }
};
