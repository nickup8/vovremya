<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Marketing attribution: restrictOnDelete чтобы физическое удаление ссылки
            // не могло обнулить исторический источник записи. Историческая attribution
            // должна сохраняться (ADR: delete tracking link отсутствует как фича).
            $table->foreignUuid('tracking_link_id')
                ->nullable()
                ->after('source')
                ->constrained('tracking_links')
                ->restrictOnDelete();

            $table->timestamp('completed_at')->nullable()->after('cancelled_at');

            $table->index('tracking_link_id');
            // Аналитика завершённых услуг/выручки: master + status + completed_at.
            $table->index(['master_id', 'status', 'completed_at'], 'appointments_master_status_completed_idx');
            // Метрика «Записи» фильтруется по created_at в разрезе мастера.
            $table->index(['master_id', 'created_at'], 'appointments_master_created_idx');
            // Метрика «Отменённые» фильтруется по cancelled_at в разрезе мастера.
            $table->index(['master_id', 'cancelled_at'], 'appointments_master_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_master_cancelled_idx');
            $table->dropIndex('appointments_master_created_idx');
            $table->dropIndex('appointments_master_status_completed_idx');
            $table->dropIndex(['tracking_link_id']);
            $table->dropConstrainedForeignId('tracking_link_id');
            $table->dropColumn('completed_at');
        });
    }
};
