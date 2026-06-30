<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_appointment_time_in_utc_converted_from_master_timezone(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $bookingService = app(BookingService::class);

        $appointment = $bookingService->createAppointment(
            $master,
            $service,
            '2026-07-01',
            '09:00',
            'widget',
        );

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'start_time' => '2026-07-01 06:00:00',
        ]);
    }

    public function test_it_stores_rescheduled_time_in_utc(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $bookingService = app(BookingService::class);

        $appointment = $bookingService->createAppointment(
            $master,
            $service,
            '2026-07-01',
            '09:00',
            'widget',
        );

        $bookingService->rescheduleAppointment(
            $appointment,
            '2026-07-01',
            '14:00',
        );

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'start_time' => '2026-07-01 11:00:00',
        ]);
    }
}
