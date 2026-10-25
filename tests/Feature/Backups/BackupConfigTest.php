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

    public function test_app_version_is_user_release_version_not_build_sha(): void
    {
        // config(app.version) — источник app_version в manifest и UI;
        // это пользовательская версия релиза, а не git SHA (SHA — app.build_sha).
        $version = (string) config('app.version');

        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{40}$/', $version);
    }

    public function test_app_version_comes_from_tracked_version_file(): void
    {
        // Единственный источник версии релиза — файл VERSION в репозитории.
        $this->assertSame(
            trim(file_get_contents(base_path('VERSION'))),
            (string) config('app.version'),
        );
    }

    public function test_app_build_sha_is_separate_from_app_version(): void
    {
        // APP_BUILD_SHA (deploy.sh: git rev-parse HEAD) не подменяет версию.
        $buildSha = (string) config('app.build_sha');

        $this->assertNotEmpty($buildSha);
        $this->assertNotSame((string) config('app.version'), $buildSha);
    }
}
