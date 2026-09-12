<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')
            ->where('provider', 'vk')
            ->whereNotNull('client_id')
            ->whereNull('source')
            ->update(['source' => 'vk']);
    }

    public function down(): void
    {
        DB::table('appointments')
            ->where('provider', 'vk')
            ->where('source', 'vk')
            ->update(['source' => null]);
    }
};
