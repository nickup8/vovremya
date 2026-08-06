<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarSnapshotDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithSchedule(): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => Carbon::tomorrow('Europe/Moscow')->dayOfWeek],
            [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => null,
                'break_end_time' => null,
                'is_working' => true,
            ],
        );

        return $master;
    }

    private function createClientForMaster(User $master): Client
    {
        return Client::create([
            'user_id' => $master->id,
            'name' => 'Тест Клиент',
            'phone' => '+79001234567',
        ]);
    }

    public function test_calendar_shows_snapshot_not_live_service_name(): void
    {
        $master = $this->createMasterWithSchedule();
        $client = $this->createClientForMaster($master);

        $service = MasterService::factory()->forMaster($master)->create([
            'price_override' => 1000.00,
            'duration_override' => 60,
        ]);
        $service->catalog->update(['title' => 'Маникюр']);

        $tomorrow = Carbon::tomorrow('Europe/Moscow');
        $bookingService = app(BookingService::class);
        $appointment = $bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            '10:00',
            'admin',
            $client->id,
        );

        // Rename service after appointment creation
        $service->catalog->update(['title' => 'Маникюр Про']);

        $response = $this->actingAs($master, 'web')
            ->getJson(route('admin.calendar.data', [
                'start' => $tomorrow->copy()->subDay()->format('Y-m-d'),
                'end' => $tomorrow->copy()->addDay()->format('Y-m-d'),
            ]));

        $response->assertOk();

        $appointments = $response->json('appointments');
        $this->assertNotEmpty($appointments);
        $this->assertSame('Маникюр', $appointments[0]['service'], 'Calendar must show snapshot service_name, not live service title');
    }

    public function test_calendar_shows_snapshot_price_not_live(): void
    {
        $master = $this->createMasterWithSchedule();
        $client = $this->createClientForMaster($master);

        $service = MasterService::factory()->forMaster($master)->create([
            'price_override' => 1000.00,
            'duration_override' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow');
        $bookingService = app(BookingService::class);
        $appointment = $bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            '10:00',
            'admin',
            $client->id,
        );

        // Change price after appointment creation
        $service->update(['price_override' => 2000.00]);

        $response = $this->actingAs($master, 'web')
            ->getJson(route('admin.calendar.data', [
                'start' => $tomorrow->copy()->subDay()->format('Y-m-d'),
                'end' => $tomorrow->copy()->addDay()->format('Y-m-d'),
            ]));

        $response->assertOk();

        $appointments = $response->json('appointments');
        $this->assertNotEmpty($appointments);
        $this->assertEquals(1000.0, $appointments[0]['price'], 'Calendar must show snapshot price, not live service price');
    }

    public function test_calendar_fallback_when_snapshot_null(): void
    {
        $master = $this->createMasterWithSchedule();
        $client = $this->createClientForMaster($master);

        $service = MasterService::factory()->forMaster($master)->create([
            'price_override' => 500.00,
            'duration_override' => 30,
        ]);
        $service->catalog->update(['title' => 'Брови']);
        // Legacy Service needed for fallback (toCalendarArray → service?->title)
        Service::factory()->create([
            'user_id' => $master->id,
            'title' => 'Брови',
            'price' => 500.00,
            'duration_minutes' => 30,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow');
        $bookingService = app(BookingService::class);
        $appointment = $bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            '10:00',
            'admin',
            $client->id,
        );

        // Clear snapshot (simulates pre-Phase-C record)
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE appointments SET service_name = NULL, price = NULL, duration = NULL WHERE id = ?',
            [$appointment->id],
        );

        $response = $this->actingAs($master, 'web')
            ->getJson(route('admin.calendar.data', [
                'start' => $tomorrow->copy()->subDay()->format('Y-m-d'),
                'end' => $tomorrow->copy()->addDay()->format('Y-m-d'),
            ]));

        $response->assertOk();

        $appointments = $response->json('appointments');
        $this->assertNotEmpty($appointments);
        $this->assertSame('Брови', $appointments[0]['service'], 'Calendar must fallback to live service title when snapshot is null');
        $this->assertEquals(500.0, $appointments[0]['price'], 'Calendar must fallback to live service price when snapshot is null');
    }
}
