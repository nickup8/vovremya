<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithWorkingHours(string $timezone = 'Europe/Moscow'): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => $timezone, 'timezone_confirmed' => true],
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $master->id, 'day_of_week' => $day],
                [
                    'start_time' => '09:00',
                    'end_time' => '19:00',
                    'is_working' => true,
                    'break_start_time' => null,
                    'break_end_time' => null,
                ]
            );
        }

        return $master;
    }

    public function test_it_stores_appointment_time_in_utc_converted_from_master_timezone(): void
    {
        $master = $this->createMasterWithWorkingHours();

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
        $master = $this->createMasterWithWorkingHours();

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $bookingService = app(BookingService::class);

        $futureDate = Carbon::tomorrow()->addDays(3)->toDateString();

        $appointment = $bookingService->createAppointment(
            $master,
            $service,
            $futureDate,
            '09:00',
            'widget',
        );

        $bookingService->rescheduleAppointment(
            $appointment,
            $futureDate,
            '14:00',
        );

        $expectedUtc = Carbon::parse($futureDate.' 14:00', 'Europe/Moscow')->utc()->format('Y-m-d H:i:s');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'start_time' => $expectedUtc,
        ]);
    }
}
