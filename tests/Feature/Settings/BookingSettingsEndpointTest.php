<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'slot_interval' => 30,
            'cancellation_deadline_hours' => null,
            'autofill_enabled' => false,
        ]);
    }

    // ═══════════════ A. slot_interval = 15 saves ═══════════════

    public function test_booking_endpoint_saves_slot_interval_15(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 24,
                'autofill_enabled' => false,
                'slot_interval' => 15,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertSame(15, $this->master->slot_interval);
    }

    // ═══════════════ B. slot_interval = 45 → 422 ═══════════════

    public function test_booking_endpoint_rejects_invalid_slot_interval(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 24,
                'autofill_enabled' => false,
                'slot_interval' => 45,
            ]);

        $response->assertSessionHasErrors('slot_interval');
    }

    // ═══════════════ C. All three fields saved ═══════════════

    public function test_booking_endpoint_saves_all_three_fields(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 48,
                'autofill_enabled' => false,
                'slot_interval' => 60,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();

        $this->assertSame(48, $this->master->cancellation_deadline_hours);
        $this->assertFalse($this->master->autofill_enabled);
        $this->assertSame(60, $this->master->slot_interval);
    }

    // ═══════════════ D. Profile / Notifications still work ═══════════════

    public function test_profile_update_still_works(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.update'), [
                'name' => 'Updated Name',
                'phone' => '+79991234567',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertSame('Updated Name', $this->master->name);
    }

    public function test_notification_update_still_works(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.update'), [
                'telegram_notifications' => true,
                'max_notifications' => false,
                'reminder_hours_before_final' => 2,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertTrue($this->master->telegram_notifications);
        $this->assertSame(2, $this->master->getReminderHoursBeforeFinal());
    }

    // ═══════════════ E. Working hours without slot_interval ═══════════════

    public function test_working_hours_without_slot_interval_succeeds(): void
    {
        $this->master->update(['slot_interval' => 30]);

        $response = $this->actingAs($this->master)
            ->put('/admin/working-hours', [
                'working_hours' => [
                    ['day_of_week' => 1, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 2, 'is_working' => false],
                    ['day_of_week' => 3, 'is_working' => false],
                    ['day_of_week' => 4, 'is_working' => false],
                    ['day_of_week' => 5, 'is_working' => false],
                    ['day_of_week' => 6, 'is_working' => false],
                    ['day_of_week' => 0, 'is_working' => false],
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertSame(30, $this->master->slot_interval, 'slot_interval should not change when not sent');
    }

    // ═══════════════ F. Legacy working hours with slot_interval ═══════════════

    public function test_working_hours_with_slot_interval_still_updates(): void
    {
        $this->master->update(['slot_interval' => 30]);

        $response = $this->actingAs($this->master)
            ->put('/admin/working-hours', [
                'slot_interval' => 15,
                'working_hours' => [
                    ['day_of_week' => 1, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 2, 'is_working' => false],
                    ['day_of_week' => 3, 'is_working' => false],
                    ['day_of_week' => 4, 'is_working' => false],
                    ['day_of_week' => 5, 'is_working' => false],
                    ['day_of_week' => 6, 'is_working' => false],
                    ['day_of_week' => 0, 'is_working' => false],
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertSame(15, $this->master->slot_interval);
    }

    // ═══════════════ G. Cache key includes slot_interval ═══════════════

    public function test_different_slot_intervals_produce_different_availability(): void
    {
        // Create a working day (Monday = 1 in Carbon)
        $this->master->workingHours()->updateOrCreate(
            ['day_of_week' => 1],
            [
                'is_working' => true,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'break_start_time' => null,
                'break_end_time' => null,
            ]
        );

        $service = app(\App\Services\Booking\AvailabilityService::class);
        $nextMonday = \Illuminate\Support\Carbon::now()->next(1)->setTime(9, 0);

        // With 60-min interval on a 3-hour window (09:00-12:00)
        $this->master->update(['slot_interval' => 60]);
        $slots60 = $service->getAvailableSlots(
            $this->master->fresh(),
            $nextMonday->copy(),
            60,
        );

        // With 30-min interval: more granular slots
        $this->master->update(['slot_interval' => 30]);
        $slots30 = $service->getAvailableSlots(
            $this->master->fresh(),
            $nextMonday->copy(),
            60,
        );

        $this->assertNotCount(count($slots60), $slots30, 'Different slot_intervals should produce different slot counts');
    }

    // ═══════════════ Cancellation deadline null ═══════════════

    public function test_cancellation_deadline_null_saves_as_null(): void
    {
        $this->master->update(['cancellation_deadline_hours' => 24]);

        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => null,
                'slot_interval' => 30,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertNull($this->master->cancellation_deadline_hours);
    }

    public function test_cancellation_deadline_omitted_preserves_existing(): void
    {
        $this->master->update(['cancellation_deadline_hours' => 24]);

        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'slot_interval' => 30,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();
        $this->assertSame(24, $this->master->cancellation_deadline_hours);
    }

    public function test_cancellation_deadline_24_and_48_still_save(): void
    {
        $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 24,
                'slot_interval' => 30,
            ]);

        $this->master->refresh();
        $this->assertSame(24, $this->master->cancellation_deadline_hours);

        $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 48,
                'slot_interval' => 30,
            ]);

        $this->master->refresh();
        $this->assertSame(48, $this->master->cancellation_deadline_hours);
    }
}
