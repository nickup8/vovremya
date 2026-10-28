<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Версия «реального изменения» записи. Каждое клиентозначимое
        // изменение (создание мастером, отмена мастером, перенос, правка
        // полей, серия) увеличивает счётчик. Событие доставки запоминает
        // версию, на которой было создано: устаревшее событие (версия
        // записи уже ушла вперёд) не отправляется и не помечает новую
        // версию доставленной. Повтор одного и того же HTTP-запроса без
        // фактических изменений версию не двигает и нового события не
        // создаёт.
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedInteger('notification_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('notification_version');
        });
    }
};
