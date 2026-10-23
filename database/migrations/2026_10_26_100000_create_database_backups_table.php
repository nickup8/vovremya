<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('trigger_type', 16);
            $table->string('status', 16);
            $table->uuid('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('dedup_key', 10)->nullable();
            $table->string('object_key', 512)->nullable();
            $table->string('manifest_key', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('pg_version', 255)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->text('prune_error_message')->nullable();
            $table->timestamps();

            $table->index(['trigger_type', 'status']);
            $table->index('finished_at');
        });

        // Не более одной активной (queued/running) копии одновременно —
        // общий запрет параллельных ручного и автоматического запусков.
        DB::statement(
            'CREATE UNIQUE INDEX database_backups_single_active_index '
            ."ON database_backups ((1)) WHERE status IN ('queued', 'running')"
        );

        // Дедупликация автоматического запуска за календарный день:
        // одна не-failed копия на dedup_key у scheduled.
        DB::statement(
            'CREATE UNIQUE INDEX database_backups_scheduled_dedup_index '
            ."ON database_backups (dedup_key) WHERE trigger_type = 'scheduled' AND status <> 'failed'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS database_backups_scheduled_dedup_index');
        DB::statement('DROP INDEX IF EXISTS database_backups_single_active_index');
        Schema::dropIfExists('database_backups');
    }
};
