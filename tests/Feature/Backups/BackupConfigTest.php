<?php

namespace Tests\Feature\Backups;

use Tests\TestCase;

class BackupConfigTest extends TestCase
{
    public function test_s3_http_timeouts_and_timeout_chain_are_configured(): void
    {
        $backups = config('backups');
        $disk = config('filesystems.disks.backups_s3');

        $this->assertArrayHasKey('s3_connect_timeout', $backups);
        $this->assertArrayHasKey('s3_request_timeout', $backups);
        $this->assertGreaterThanOrEqual(1, $backups['s3_connect_timeout']);
        $this->assertGreaterThanOrEqual(5, $backups['s3_request_timeout']);

        // Цепочка таймаутов: subprocess < job < retry_after (defaults).
        $this->assertLessThan($backups['job_timeout'], $backups['process_timeout']);
        $this->assertLessThan($backups['retry_after'], $backups['job_timeout']);
        $this->assertLessThan($backups['stale_after'], $backups['retry_after']);

        $this->assertSame('s3', $disk['driver']);
        $this->assertArrayHasKey('http', $disk);
        $this->assertArrayHasKey('connect_timeout', $disk['http']);
        $this->assertArrayHasKey('timeout', $disk['http']);
        $this->assertGreaterThan(0, $disk['http']['connect_timeout']);
        $this->assertGreaterThan(0, $disk['http']['timeout']);
    }

    public function test_queue_backups_connection_is_separate_from_default(): void
    {
        $this->assertSame('database', config('queue.connections.backups.driver'));
        $this->assertSame('backups', config('queue.connections.backups.queue'));
        $this->assertArrayHasKey('after_commit', config('queue.connections.backups'));
        // Общая очередь default не перенацелена на backups.
        $this->assertNotSame('backups', config('queue.connections.database.queue'));
    }

    public function test_app_version_is_used_for_manifest(): void
    {
        // config(app.version) — источник app_version в manifest.
        // deploy.sh пишет git SHA в APP_VERSION; здесь фиксируем контракт.
        $this->assertNotEmpty(config('app.version'));
        $this->assertSame((string) config('app.version'), config('app.version'));
    }
}
