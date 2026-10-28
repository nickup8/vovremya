<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Поколение расписания записи: растёт при каждом изменении времени,
        // мастера или получателя (клиента). Напоминание несёт снимок версии
        // на момент постановки и сверяется с ней перед отправкой и при
        // записи результата — перенос A→B→A не совпадает с исходной
        // версией, смена получателя отсекает чужую отправку, а изменение
        // цены версию не двигает и не сбивает напоминания.
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedInteger('schedule_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('schedule_version');
        });
    }
};
