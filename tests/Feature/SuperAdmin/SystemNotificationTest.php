<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\PlatformPermission;
use App\Models\PlatformAdminAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ── A. Superadmin send to single master ──

    public function test_root_can_send_notification_to_active_master(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $master->id,
                'title' => 'Тест',
                'body' => 'Текст уведомления',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $master->id,
        ]);
    }

    // ── B. Unauthorized limited admin → 403 ──

    public function test_unauthorized_limited_admin_cannot_send(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        $master = User::factory()->master()->create();

        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::UsersView->value],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $master->id,
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertStatus(403);
    }

    public function test_limited_admin_with_notifications_send_can_send(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        $master = User::factory()->master()->create();

        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsSend->value],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $master->id,
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master->id,
        ]);
    }

    // ── C. Send all: active master gets, blocked doesn't, non-master doesn't ──

    public function test_send_all_reaches_active_masters_only(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $activeMaster = User::factory()->master()->create();
        $blockedMaster = User::factory()->master()->create(['is_blocked' => true]);
        $nonMaster = User::factory()->create(['is_master' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Всем',
                'body' => 'Текст',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $activeMaster->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $blockedMaster->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $nonMaster->id,
        ]);
    }

    // ── D. Cannot send to non-master/blocked user ──

    public function test_cannot_send_to_non_master(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $nonMaster = User::factory()->create(['is_master' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $nonMaster->id,
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertNotFound();
    }

    public function test_cannot_send_to_blocked_master(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $blocked = User::factory()->master()->create(['is_blocked' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $blocked->id,
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertNotFound();
    }

    // ── E. Validation ──

    public function test_validation_requires_title_and_body(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
            ])
            ->assertSessionHasErrors(['title', 'body']);
    }

    public function test_validation_requires_valid_recipient_type(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'invalid',
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertSessionHasErrors(['recipient_type']);
    }

    public function test_user_id_required_when_recipient_type_is_user(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertSessionHasErrors(['user_id']);
    }

    // ── F. Master sees notifications in page props ──

    public function test_master_sees_notification_in_page(): void
    {
        $master = User::factory()->master()->create();

        $master->notify(new \App\Notifications\SystemNotification('Заголовок', 'Тело'));

        $this->actingAs($master)
            ->get(route('admin.calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread_count', 1)
            );
    }

    // ── G. Unread count is correct ──

    public function test_unread_count_correct(): void
    {
        $master = User::factory()->master()->create();

        $master->notify(new \App\Notifications\SystemNotification('1', 'Одно'));
        $master->notify(new \App\Notifications\SystemNotification('2', 'Два'));

        $this->actingAs($master)
            ->get(route('admin.calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread_count', 2)
            );
    }

    // ── H. Master can mark own notification read ──

    public function test_master_can_mark_own_read(): void
    {
        $master = User::factory()->master()->create();
        $master->notify(new \App\Notifications\SystemNotification('Тест', 'Тело'));
        $notification = $master->notifications()->first();

        $this->actingAs($master)
            ->post(route('admin.notifications.read', $notification->id))
            ->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
        ]);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    // ── I. Master cannot mark another's notification read ──

    public function test_master_cannot_mark_others_read(): void
    {
        $master = User::factory()->master()->create();
        $other = User::factory()->master()->create();

        $other->notify(new \App\Notifications\SystemNotification('Чужое', 'Тело'));
        $notification = $other->notifications()->first();

        $this->actingAs($master)
            ->post(route('admin.notifications.read', $notification->id))
            ->assertRedirect();

        $this->assertNull($notification->fresh()->read_at);
    }

    // ── J. Mark all read ──

    public function test_mark_all_read_works(): void
    {
        $master = User::factory()->master()->create();

        $master->notify(new \App\Notifications\SystemNotification('1', 'Текст 1'));
        $master->notify(new \App\Notifications\SystemNotification('2', 'Текст 2'));

        $this->actingAs($master)
            ->post(route('admin.notifications.readAll'))
            ->assertRedirect();

        $this->assertEquals(0, $master->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_read_does_not_affect_others(): void
    {
        $master = User::factory()->master()->create();
        $other = User::factory()->master()->create();

        $master->notify(new \App\Notifications\SystemNotification('Моё', 'Текст'));
        $other->notify(new \App\Notifications\SystemNotification('Чужое', 'Текст'));

        $this->actingAs($master)
            ->post(route('admin.notifications.readAll'))
            ->assertRedirect();

        $this->assertEquals(1, $other->fresh()->unreadNotifications()->count());
    }

    // ── K. Notification data shape ──

    public function test_notification_data_shape(): void
    {
        $master = User::factory()->master()->create();

        $master->notify(new \App\Notifications\SystemNotification('Заголовок', 'Текст'));

        $this->actingAs($master)
            ->get(route('admin.calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications.items', 1)
                ->where('notifications.items.0.kind', 'system')
                ->where('notifications.items.0.title', 'Заголовок')
                ->where('notifications.items.0.body', 'Текст')
            );
    }

    // ── L. Unauthenticated access ──

    public function test_unauthenticated_cannot_send(): void
    {
        $master = User::factory()->master()->create();

        $this->post(route('super_admin.notifications.send'), [
            'recipient_type' => 'all',
            'title' => 'Тест',
            'body' => 'Текст',
        ])->assertRedirect();
    }

    // ── M. Audit log ──

    public function test_audit_log_recorded_for_single_send(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $master->id,
                'title' => 'Тест',
                'body' => 'Текст',
            ]);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'super_admin_id' => $root->id,
            'action' => 'notification.sent',
        ]);
    }

    public function test_audit_log_recorded_for_broadcast_send(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Всем',
                'body' => 'Текст',
            ]);

        $log = \App\Models\SuperAdminAuditLog::where('super_admin_id', $root->id)
            ->where('action', 'notification.sent')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('all', $log->metadata['recipient_type']);
        $this->assertEquals('Всем', $log->metadata['title']);
    }
}
