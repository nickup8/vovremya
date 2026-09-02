<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
    }

    public function test_master_saves_cancellation_and_autofill(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 24,
                'autofill_enabled' => false,
                'slot_interval' => 30,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();

        $this->assertSame(24, $this->master->cancellation_deadline_hours);
        $this->assertFalse($this->master->autofill_enabled);
    }

    public function test_invalid_cancellation_deadline_is_rejected(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.booking.update'), [
                'cancellation_deadline_hours' => 999,
                'slot_interval' => 30,
            ]);

        $response->assertSessionHasErrors('cancellation_deadline_hours');
    }

    public function test_old_user_without_settings_gets_defaults(): void
    {
        $this->master->update(['settings' => null]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.settings'));

        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('admin/settings')
            ->where('profile.reminder_hours_before_final', 3)
        );
    }

    public function test_settings_page_renders_booking_data(): void
    {
        $this->master->update([
            'cancellation_deadline_hours' => 48,
            'settings' => [
                'timezone' => 'Europe/Moscow',
                'timezone_confirmed' => true,
                'reminder_hours_before_final' => 2,
            ],
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.settings'));

        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('admin/settings')
            ->where('profile.cancellation_deadline_hours', 48)
            ->where('profile.reminder_hours_before_final', 2)
        );
    }
}
