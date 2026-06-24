<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('telegram_id')->nullable();
            $table->string('max_id')->nullable();
            $table->string('name');
            $table->string('auth_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->unique(['user_id', 'phone']);
            $table->index('user_id');
            $table->index('phone');
            $table->index('telegram_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
