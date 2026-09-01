<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_notification_update_only_changes_notification_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'phone' => '+79001112233',
            'settings' => [
                'telegram_notifications' => false,
                'max_notifications' => false,
                'reminder_hours_before_final' => 3,
                'booking_flow_type' => 'free_verification',
                'cancellation_deadline_hours' => null,
            ],
        ]);

        $response = $this->actingAs($user)
            ->put(route('admin.settings.update'), [
                'telegram_notifications' => true,
                'max_notifications' => true,
                'reminder_hours_before_final' => 12,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $user->refresh();

        $this->assertTrue($user->telegram_notifications);
        $this->assertTrue($user->max_notifications);
        $this->assertSame(12, $user->getReminderHoursBeforeFinal());
    }

    public function test_notification_update_does_not_change_profile_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'phone' => '+79001112233',
            'settings' => [
                'telegram_notifications' => false,
                'max_notifications' => false,
                'reminder_hours_before_final' => 3,
                'booking_flow_type' => 'free_verification',
                'cancellation_deadline_hours' => null,
                'autofill_enabled' => false,
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.settings.update'), [
                'telegram_notifications' => true,
                'max_notifications' => true,
                'reminder_hours_before_final' => 0,
            ]);

        $user->refresh();

        $this->assertSame('Original Name', $user->name);
        $this->assertSame('+79001112233', $user->phone);
        $this->assertSame('free_verification', $user->getBookingFlowType());
        $this->assertNull($user->cancellation_deadline_hours);
        $this->assertFalse($user->autofill_enabled);
    }

    public function test_notification_update_preserves_other_settings_json_keys(): void
    {
        $user = User::factory()->create([
            'cancellation_deadline_hours' => 24,
            'autofill_enabled' => true,
            'settings' => [
                'timezone' => 'Europe/Moscow',
                'timezone_confirmed' => true,
                'telegram_notifications' => false,
                'max_notifications' => false,
                'reminder_hours_before_final' => 3,
                'booking_flow_type' => 'prepayment_custom',
                'custom_prepayment_message' => 'Pay here',
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.settings.update'), [
                'telegram_notifications' => true,
                'reminder_hours_before_final' => 1,
            ]);

        $user->refresh();

        $this->assertSame('Europe/Moscow', $user->getTimezone());
        $this->assertTrue($user->isTimezoneConfirmed());
        $this->assertSame('prepayment_custom', $user->getBookingFlowType());
        $this->assertSame('Pay here', $user->getCustomPrepaymentMessage());
        $this->assertSame(24, $user->cancellation_deadline_hours);
        $this->assertTrue($user->autofill_enabled);
        $this->assertTrue($user->telegram_notifications);
        $this->assertSame(1, $user->getReminderHoursBeforeFinal());
    }
}
