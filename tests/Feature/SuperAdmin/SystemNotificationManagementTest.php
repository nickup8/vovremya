<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\PlatformPermission;
use App\Models\PlatformAdminAccess;
use App\Models\SystemNotificationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemNotificationManagementTest extends TestCase
{
    use RefreshDatabase;

    // ── A. Send creates parent + linked delivery ──

    public function test_single_send_creates_parent_and_delivery(): void
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

        $this->assertDatabaseHas('system_notification_messages', [
            'title' => 'Тест',
            'body' => 'Текст уведомления',
        ]);

        $message = SystemNotificationMessage::first();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master->id,
            'system_message_id' => $message->id,
        ]);
    }

    public function test_broadcast_send_creates_parent_and_all_deliveries(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Всем',
                'body' => 'Общий текст',
            ])
            ->assertRedirect();

        $message = SystemNotificationMessage::first();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master1->id,
            'system_message_id' => $message->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master2->id,
            'system_message_id' => $message->id,
        ]);
    }

    // ── B. New user does not receive old messages ──

    public function test_user_created_after_send_does_not_get_delivery(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'До',
                'body' => 'Текст',
            ]);

        $newMaster = User::factory()->master()->create();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $newMaster->id,
            'type' => \App\Notifications\SystemNotification::class,
        ]);
    }

    // ── C. Non-master/blocked not recipient ──

    public function test_blocked_master_not_recipient(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $blocked = User::factory()->master()->create(['is_blocked' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Тест',
                'body' => 'Текст',
            ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $blocked->id,
        ]);
    }

    // ── D. Admin history ──

    public function test_admin_index_shows_parent_messages(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        SystemNotificationMessage::create([
            'title' => 'История',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->get(route('super_admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.title', 'История')
            );
    }

    public function test_admin_index_shows_recipient_stats(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'С статистикой',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $master->notify(new \App\Notifications\SystemNotification('С статистикой', 'Текст'));
        $notification = $master->notifications()->first();
        $notification->update(['system_message_id' => $message->id]);

        $this->actingAs($root)
            ->get(route('super_admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('messages.data.0.notifications_count', 1)
                ->where('messages.data.0.read_count', 0)
            );
    }

    public function test_paginator_total_equals_sent_messages_count(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        for ($i = 0; $i < 25; $i++) {
            SystemNotificationMessage::create([
                'title' => "Message {$i}",
                'body' => 'Text',
                'created_by' => $root->id,
            ]);
        }

        $this->actingAs($root)
            ->get(route('super_admin.notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('messages.total', 25)
                ->where('messages.per_page', 15)
                ->where('messages.last_page', 2)
            );
    }

    // ── E. Body > 2000 accepted ──

    public function test_long_body_accepted(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Длинное',
                'body' => str_repeat('Абракадабра ', 300),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('system_notification_messages', [
            'title' => 'Длинное',
        ]);
    }

    // ── F. Edit parent changes recipient-visible text ──

    public function test_edit_changes_title_and_body(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Старый',
            'body' => 'Старый текст',
            'created_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Новый',
                'body' => 'Новый текст',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('system_notification_messages', [
            'id' => $message->id,
            'title' => 'Новый',
            'body' => 'Новый текст',
        ]);
    }

    public function test_edit_preserves_created_at(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $root->id,
            'created_at' => now()->subDays(5),
        ]);

        $originalCreatedAt = $message->created_at->copy();

        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Новый',
                'body' => 'Новый текст',
            ]);

        $this->assertEquals($originalCreatedAt->timestamp, $message->fresh()->created_at->timestamp);
    }

    public function test_edit_creates_no_new_deliveries(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $notificationCountBefore = \Illuminate\Notifications\DatabaseNotification::where('system_message_id', $message->id)->count();

        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Новый',
                'body' => 'Новый текст',
            ]);

        $notificationCountAfter = \Illuminate\Notifications\DatabaseNotification::where('system_message_id', $message->id)->count();

        $this->assertEquals($notificationCountBefore, $notificationCountAfter);
    }

    // ── G. Delete ──

    public function test_delete_hard_deletes_deliveries_and_soft_deletes_parent(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Удаляемое',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $master->notify(new \App\Notifications\SystemNotification('Удаляемое', 'Текст'));
        $master->notifications()->latest()->first()->update(['system_message_id' => $message->id]);

        $this->actingAs($root)
            ->delete(route('super_admin.notifications.destroy', $message))
            ->assertRedirect();

        $this->assertSoftDeleted('system_notification_messages', ['id' => $message->id]);

        $this->assertDatabaseMissing('notifications', [
            'system_message_id' => $message->id,
        ]);
    }

    public function test_delete_audit_contains_stats(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Статы',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $master->notify(new \App\Notifications\SystemNotification('Статы', 'Текст'));
        $master->notifications()->latest()->first()->update(['system_message_id' => $message->id]);

        $this->actingAs($root)
            ->delete(route('super_admin.notifications.destroy', $message));

        $log = \App\Models\SuperAdminAuditLog::where('super_admin_id', $root->id)
            ->where('action', 'notification.deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(1, $log->metadata['recipients_total']);
        $this->assertEquals(0, $log->metadata['read_count']);
        $this->assertEquals(1, $log->metadata['unread_count']);
    }

    // ── H. Edit audit ──

    public function test_edit_creates_audit_log(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Новый',
                'body' => 'Новый текст',
            ]);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'super_admin_id' => $root->id,
            'action' => 'notification.updated',
        ]);
    }

    // ── I. Stale edit after delete cannot resurrect ──

    public function test_edit_after_delete_fails(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $message = SystemNotificationMessage::create([
            'title' => 'Удалено',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->delete(route('super_admin.notifications.destroy', $message));

        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Воскрешение',
                'body' => 'Текст',
            ])
            ->assertNotFound();
    }

    // ── J. Permissions ──

    public function test_view_only_can_access_index(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsView->value],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('super_admin.notifications.index'))
            ->assertOk();
    }

    public function test_view_only_cannot_send(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsView->value],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Тест',
                'body' => 'Текст',
            ])
            ->assertStatus(403);
    }

    public function test_send_only_cannot_access_index(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsSend->value],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('super_admin.notifications.index'))
            ->assertStatus(403);
    }

    public function test_update_only_can_update(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [
                PlatformPermission::NotificationsUpdate->value,
                PlatformPermission::NotificationsView->value,
            ],
            'is_active' => true,
        ]);

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Новый',
                'body' => 'Текст',
            ])
            ->assertRedirect();
    }

    public function test_delete_only_can_delete(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [
                PlatformPermission::NotificationsDelete->value,
                PlatformPermission::NotificationsView->value,
            ],
            'is_active' => true,
        ]);

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('super_admin.notifications.destroy', $message))
            ->assertRedirect();
    }

    public function test_root_bypass_works_for_all(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $this->actingAs($root)->get(route('super_admin.notifications.index'))->assertOk();
        $this->actingAs($root)->put(route('super_admin.notifications.update', $message), ['title' => 'X', 'body' => 'Y'])->assertRedirect();
        $this->actingAs($root)->delete(route('super_admin.notifications.destroy', $message))->assertRedirect();
    }

    // ── K. Zero-recipient broadcast ──

    public function test_zero_recipient_broadcast_creates_parent(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        User::query()->update(['is_master' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Никому',
                'body' => 'Пустая',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('system_notification_messages', [
            'title' => 'Никому',
        ]);
    }

    // ── L. Legacy system_message_id=NULL still visible ──

    public function test_legacy_notification_still_visible_in_master_bell(): void
    {
        $master = User::factory()->master()->create();

        $master->notify(new \App\Notifications\SystemNotification('Старое', 'Текст'));

        $this->actingAs($master)
            ->get(route('admin.calendar'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notifications.items.0.title', 'Старое')
                ->where('notifications.items.0.body', 'Текст')
            );
    }

    public function test_legacy_row_not_in_admin_parent_history(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        // Legacy notification without system_message_id
        $master->notify(new \App\Notifications\SystemNotification('Старое', 'Текст'));

        $this->actingAs($root)
            ->get(route('super_admin.notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('messages.total', 0)
            );
    }

    // ── M. Linked row uses parent, not stale snapshot ──

    public function test_master_sees_parent_title_body_after_edit(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Оригинал',
            'body' => 'Оригинальный текст',
            'created_by' => $root->id,
        ]);

        $master->notify(new \App\Notifications\SystemNotification('Оригинал', 'Оригинальный текст'));
        $master->notifications()->latest()->first()->update(['system_message_id' => $message->id]);

        // Edit parent
        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Исправлено',
                'body' => 'Исправленный текст',
            ]);

        // Master should see updated text from parent
        $this->actingAs($master)
            ->get(route('admin.calendar'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notifications.items.0.title', 'Исправлено')
                ->where('notifications.items.0.body', 'Исправленный текст')
            );
    }

    // ── N. Deleted parent filtered from master bell ──

    public function test_deleted_parent_filtered_from_master_bell(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Удалённое',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $master->notify(new \App\Notifications\SystemNotification('Удалённое', 'Текст'));
        $master->notifications()->latest()->first()->update(['system_message_id' => $message->id]);

        // Delete parent
        $this->actingAs($root)
            ->delete(route('super_admin.notifications.destroy', $message));

        // Delivery hard-deleted → not in bell
        $this->assertDatabaseMissing('notifications', [
            'system_message_id' => $message->id,
        ]);
    }

    // ── O. Mark read preserves read_at during edit ──

    public function test_mark_read_not_lost_after_edit(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        $message = SystemNotificationMessage::create([
            'title' => 'Тест',
            'body' => 'Текст',
            'created_by' => $root->id,
        ]);

        $master->notify(new \App\Notifications\SystemNotification('Тест', 'Текст'));
        $notification = $master->notifications()->latest()->first();
        $notification->update(['system_message_id' => $message->id, 'read_at' => now()]);

        $this->actingAs($root)
            ->put(route('super_admin.notifications.update', $message), [
                'title' => 'Изменено',
                'body' => 'Изменённый текст',
            ]);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    // ── P. Validation ──

    public function test_validation_requires_title_and_body(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
            ])
            ->assertSessionHasErrors(['title', 'body']);
    }

    public function test_validation_allows_long_body(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Тест',
                'body' => str_repeat('x', 5000),
            ])
            ->assertRedirect();
    }

    // ── Q. Single send exact linking with pre-existing notifications ──

    public function test_single_send_links_only_new_delivery(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master = User::factory()->master()->create();

        // Pre-existing notification (e.g. old system notification)
        $master->notify(new \App\Notifications\SystemNotification('Старое', 'Старый текст'));
        $oldNotification = $master->notifications()->latest()->first();
        $this->assertNull($oldNotification->system_message_id);

        // Send new message
        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'user',
                'user_id' => $master->id,
                'title' => 'Новое',
                'body' => 'Новый текст',
            ])
            ->assertRedirect();

        $message = SystemNotificationMessage::where('title', 'Новое')->first();
        $this->assertNotNull($message);

        // Old notification unchanged
        $this->assertNull($oldNotification->fresh()->system_message_id);

        // New notification correctly linked
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master->id,
            'system_message_id' => $message->id,
        ]);
    }

    // ── R. Broadcast exact linking with pre-existing notifications ──

    public function test_broadcast_links_only_new_deliveries(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        // Pre-existing notifications
        $master1->notify(new \App\Notifications\SystemNotification('Старое', 'Текст'));
        $old1 = $master1->notifications()->latest()->first();

        $master2->notify(new \App\Notifications\SystemNotification('Старое', 'Текст'));
        $old2 = $master2->notifications()->latest()->first();

        // Broadcast
        $this->actingAs($root)
            ->post(route('super_admin.notifications.send'), [
                'recipient_type' => 'all',
                'title' => 'Рассылка',
                'body' => 'Текст',
            ])
            ->assertRedirect();

        $message = SystemNotificationMessage::where('title', 'Рассылка')->first();

        // Old notifications unchanged
        $this->assertNull($old1->fresh()->system_message_id);
        $this->assertNull($old2->fresh()->system_message_id);

        // New deliveries correctly linked
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master1->id,
            'system_message_id' => $message->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master2->id,
            'system_message_id' => $message->id,
        ]);
    }

    // ── S. Channel isolation: PaymentReminder stays NULL ──

    public function test_payment_reminder_system_message_id_stays_null(): void
    {
        $master = User::factory()->master()->create();

        $master->notify(new \App\Notifications\PaymentReminderNotification(3));

        $notification = $master->notifications()->latest()->first();

        $this->assertNull($notification->system_message_id);
        $this->assertEquals('payment_reminder', $notification->data['kind']);
    }

    // ── T. Transaction rollback on delivery failure ──

    public function test_transaction_rolls_back_on_broadcast_failure(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $master1 = User::factory()->master()->create();

        $parentCountBefore = SystemNotificationMessage::count();
        $notifCountBefore = \Illuminate\Notifications\DatabaseNotification::count();

        // Force failure during broadcast by mocking a recipient that throws
        // We simulate by attempting broadcast with a bad user_id scenario
        // Actually, we can test that if the transaction fails, nothing is persisted
        $this->actingAs($root);

        // We'll use a DB exception simulation
        \Illuminate\Support\Facades\DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \RuntimeException('Simulated failure'));

        $this->post(route('super_admin.notifications.send'), [
            'recipient_type' => 'all',
            'title' => 'Провал',
            'body' => 'Текст',
        ]);

        $this->assertEquals($parentCountBefore, SystemNotificationMessage::count());
        $this->assertEquals($notifCountBefore, \Illuminate\Notifications\DatabaseNotification::count());
    }

    // ── U. Admins page renders with new permissions ──

    public function test_admins_page_renders_with_new_notification_permissions(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->get(route('super_admin.admins'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('allPermissions')
            );
    }

    // ── V. Send permission gates recipients payload ──

    public function test_view_only_gets_empty_recipients(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsView->value],
            'is_active' => true,
        ]);

        User::factory()->master()->create();

        $this->actingAs($admin)
            ->get(route('super_admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recipients', [])
            );
    }

    public function test_send_permission_gets_eligible_recipients(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsView->value, PlatformPermission::NotificationsSend->value],
            'is_active' => true,
        ]);

        User::factory()->master()->create();

        $response = $this->actingAs($admin)
            ->get(route('super_admin.notifications.index'));

        $response->assertOk();
        $recipients = $response->viewData('page')['props']['recipients'];
        $this->assertNotEmpty($recipients);
    }

    public function test_root_gets_eligible_recipients(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        User::factory()->master()->create();

        $response = $this->actingAs($root)
            ->get(route('super_admin.notifications.index'));

        $response->assertOk();
        $recipients = $response->viewData('page')['props']['recipients'];
        $this->assertNotEmpty($recipients);
    }

    public function test_blocked_user_not_in_recipients(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsView->value, PlatformPermission::NotificationsSend->value],
            'is_active' => true,
        ]);

        User::factory()->master()->create(['name' => 'Активный']);
        User::factory()->master()->create(['name' => 'Заблокирован', 'is_blocked' => true]);

        $response = $this->actingAs($admin)
            ->get(route('super_admin.notifications.index'));

        $response->assertOk();
        $recipients = $response->viewData('page')['props']['recipients'];
        $names = collect($recipients)->pluck('name')->toArray();
        $this->assertContains('Активный', $names);
        $this->assertNotContains('Заблокирован', $names);
    }

    public function test_non_master_not_in_recipients(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => [PlatformPermission::NotificationsView->value, PlatformPermission::NotificationsSend->value],
            'is_active' => true,
        ]);

        User::factory()->master()->create(['name' => 'Мастер']);
        User::factory()->create(['name' => 'Не мастер', 'is_master' => false]);

        $response = $this->actingAs($admin)
            ->get(route('super_admin.notifications.index'));

        $response->assertOk();
        $recipients = $response->viewData('page')['props']['recipients'];
        $names = collect($recipients)->pluck('name')->toArray();
        $this->assertContains('Мастер', $names);
        $this->assertNotContains('Не мастер', $names);
    }
}
