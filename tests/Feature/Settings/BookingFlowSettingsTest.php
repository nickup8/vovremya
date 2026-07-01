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

    public function test_master_saves_booking_flow_settings(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.update'), [
                'name' => $this->master->name,
                'phone' => $this->master->phone,
                'booking_flow_type' => 'prepayment_custom',
                'custom_prepayment_message' => 'Реквизиты для перевода: Сбербанк',
                'reminder_hours_before_final' => 2,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();

        $this->assertEquals('prepayment_custom', $this->master->getBookingFlowType());
        $this->assertEquals('Реквизиты для перевода: Сбербанк', $this->master->getCustomPrepaymentMessage());
        $this->assertEquals(2, $this->master->getReminderHoursBeforeFinal());
    }

    public function test_invalid_booking_flow_type_is_rejected(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.update'), [
                'name' => $this->master->name,
                'phone' => $this->master->phone,
                'booking_flow_type' => 'nonsense_value',
                'reminder_hours_before_final' => 3,
            ]);

        $response->assertSessionHasErrors('booking_flow_type');
    }

    public function test_invalid_reminder_hours_is_rejected(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.update'), [
                'name' => $this->master->name,
                'phone' => $this->master->phone,
                'booking_flow_type' => 'free_verification',
                'reminder_hours_before_final' => 5,
            ]);

        $response->assertSessionHasErrors('reminder_hours_before_final');
    }

    public function test_old_user_without_settings_gets_defaults(): void
    {
        $this->master->update(['settings' => null]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.settings'));

        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('admin/settings')
            ->where('profile.booking_flow_type', 'free_verification')
            ->where('profile.reminder_hours_before_final', 3)
            ->where('profile.custom_prepayment_message', null)
        );
    }

    public function test_custom_prepayment_message_is_nullable(): void
    {
        $response = $this->actingAs($this->master)
            ->put(route('admin.settings.update'), [
                'name' => $this->master->name,
                'phone' => $this->master->phone,
                'booking_flow_type' => 'free_verification',
                'custom_prepayment_message' => null,
                'reminder_hours_before_final' => 3,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->master->refresh();

        $this->assertNull($this->master->getCustomPrepaymentMessage());
    }

    public function test_settings_page_renders_booking_flow_data(): void
    {
        $this->master->update([
            'settings' => [
                'timezone' => 'Europe/Moscow',
                'timezone_confirmed' => true,
                'booking_flow_type' => 'prepayment_custom',
                'custom_prepayment_message' => 'Тестовое сообщение',
                'reminder_hours_before_final' => 2,
            ],
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.settings'));

        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('admin/settings')
            ->where('profile.booking_flow_type', 'prepayment_custom')
            ->where('profile.custom_prepayment_message', 'Тестовое сообщение')
            ->where('profile.reminder_hours_before_final', 2)
        );
    }
}
