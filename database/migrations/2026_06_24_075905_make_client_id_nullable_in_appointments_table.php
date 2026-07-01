<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No-op: client_id is already nullable from the original create_appointments migration.
    }

    public function down(): void
    {
        // No-op: nothing to revert.
    }
};
