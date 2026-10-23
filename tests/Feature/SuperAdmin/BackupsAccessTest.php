<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\BackupTrigger;
use App\Enums\PlatformPermission;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\PlatformAdminAccess;
use App\Models\User;
use App\Services\Backups\BackupProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Backups\FakeBackupProcessRunner;
use Tests\TestCase;

class BackupsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backups.tmp_dir' => sys_get_temp_dir().'/irsi-backups-test-'.uniqid(),
            'backups.scheduled.enabled' => false,
        ]);
    }

    public function test_root_can_view_backups_page(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->get(route('super_admin.backups'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/Backups')
            ->has('backups', 0)
            ->where('stale_warning', true)
            ->where('active', false)
            ->where('schedule.enabled', false)
            ->where('display_timezone', 'Europe/Moscow'),
        );
    }

    public function test_regular_user_cannot_view_backups_page(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $response = $this->actingAs($user)->get(route('super_admin.backups'));

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('super_admin.backups'))->assertRedirect();
    }

    public function test_limited_platform_admin_cannot_view_backups_page_even_with_all_permissions(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);

        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => array_map(fn (PlatformPermission $p) => $p->value, PlatformPermission::cases()),
            'is_active' => true,
            'granted_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('super_admin.backups'));

        $response->assertStatus(403);
    }

    public function test_limited_platform_admin_cannot_request_backup(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);

        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => array_map(fn (PlatformPermission $p) => $p->value, PlatformPermission::cases()),
            'is_active' => true,
            'granted_by' => $admin->id,
        ]);

        Queue::fake();

        $response = $this->actingAs($admin)->post(route('super_admin.backups.store'));

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_manual_request_queues_job_without_running_pg_dump_in_http(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $runner = new FakeBackupProcessRunner;
        $this->app->instance(BackupProcessRunner::class, $runner);

        Queue::fake();

        $response = $this->actingAs($admin)->post(route('super_admin.backups.store'));

        $response->assertSessionHas('success');
        $response->assertRedirect();

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        Queue::assertPushedOn('backups', RunDatabaseBackupJob::class);

        // HTTP-запрос не запускал pg_dump.
        $this->assertSame([], $runner->calls);

        $this->assertDatabaseHas('database_backups', [
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => 'queued',
            'requested_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'super_admin_id' => $admin->id,
            'action' => 'backup.requested',
        ]);
    }

    public function test_second_manual_click_is_blocked_while_active(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        Queue::fake();

        $this->actingAs($admin)->post(route('super_admin.backups.store'))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('super_admin.backups.store'))->assertSessionHas('error');

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        $this->assertDatabaseCount('database_backups', 1);
    }
}
