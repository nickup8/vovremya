<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Поканальная доставка одного события уведомления. Строка создаётся
        // claim'ом (status pending, claim_token, lease_expires_at) ДО HTTP и
        // переходит в delivered только когда API принял сообщение (с
        // provider_message_id). Два пересекающихся worker'а сериализуются
        // на этой строке; null от API / исключение никогда не записываются
        // как доставка. no_channel — явный терминальный исход «нет
        // доступного канала» без HTTP. superseded — событие устарело
        // (версия записи ушла вперёд) и не должно отправляться.
        Schema::create('appointment_notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_notification_event_id')
                ->constrained('appointment_notification_events')
                ->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['appointment_notification_event_id', 'channel'],
                'appt_notif_delivery_unique'
            );
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notification_deliveries');
    }
};
