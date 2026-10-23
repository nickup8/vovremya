<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\BackupError;
use Tests\TestCase;

class BackupErrorSanitizeTest extends TestCase
{
    public function test_sanitize_redacts_password_and_pgpassword(): void
    {
        $this->assertSame(
            'error password=*** ok',
            BackupError::sanitize('error password=SuperSecret ok'),
        );

        $this->assertStringContainsString(
            'PGPASSWORD=***',
            BackupError::sanitize('env PGPASSWORD=abc123 end'),
        );

        $this->assertStringContainsString(
            'secret=***',
            BackupError::sanitize('cfg secret=abc123 end'),
        );
    }

    public function test_sanitize_redacts_configured_db_password_and_s3_secret(): void
    {
        config([
            'database.connections.pgsql.password' => 'CfgDbPass-999',
            'filesystems.disks.backups_s3.secret' => 'CfgS3Secret-888',
        ]);

        $out = BackupError::sanitize('boom CfgDbPass-999 and CfgS3Secret-888');

        $this->assertStringNotContainsString('CfgDbPass-999', $out);
        $this->assertStringNotContainsString('CfgS3Secret-888', $out);
        $this->assertStringContainsString('***', $out);
    }

    public function test_from_stderr_limits_length(): void
    {
        $long = str_repeat('x', 1000);
        $result = BackupError::fromStderr($long);

        $this->assertLessThanOrEqual(500, strlen($result));
    }

    public function test_from_throwable_generic_for_non_backup_exception(): void
    {
        config(['database.connections.pgsql.password' => 'hunter2-secret']);

        $result = BackupError::fromThrowable(new \RuntimeException('password=hunter2-secret in message'));

        $this->assertStringNotContainsString('hunter2-secret', $result);
        $this->assertStringContainsString('RuntimeException', $result);
    }
}
