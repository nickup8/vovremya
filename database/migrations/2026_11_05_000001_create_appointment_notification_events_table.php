<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional outbox клиентских уведомлений о записи: одна строка
        // = одно реальное изменение (создание мастером, отмена мастером,
        // перенос, правка полей, действие по серии, подтверждение брони
        // из widget). Пишется В ТОЙ ЖЕ транзакции, что и изменение записи:
        // rollback убирает и намерение, commit оставляет устойчивую строку
        // даже если постановка в очередь после commit упала (dispatched_at
        // остаётся NULL — recovery переставит job).
        //
        // event_version = appointments.notification_version на момент
        // изменения: (appointment, event_type, event_version) — идентичность
        // события. A→B→A даёт две разные версии (два события); повтор
        // того же запроса без изменений не двигает версию и не плодит
        // события. payload — снимок текста/метаданных, чтобы позднейшая
        // правка каталога или мастерa не изменила уже зафиксированное
        // сообщение.
        Schema::create('appointment_notification_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')
                ->constrained('appointments')
                ->cascadeOnDelete();
            // Для серийных действий событие одно на серию; appointment_id
            // указывает на «якорную» запись (первую затронутую), а
            // recurring_series_id связывает контекст серии.
            $table->foreignUuid('recurring_series_id')
                ->nullable()
                ->constrained('recurring_appointment_series')
                ->nullOnDelete();
            $table->string('event_type', 32);
            $table->unsignedInteger('event_version');
            $table->json('payload');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['appointment_id', 'event_type', 'event_version'],
                'appt_notif_event_unique'
            );
            $table->index('dispatched_at');
            $table->index(['recurring_series_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notification_events');
    }
};
