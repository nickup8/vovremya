<?php

namespace Tests\Unit;

use App\Models\Appointment;
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

class AppointmentSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(BookingService::class);
    }

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

    public function test_backfill_populates_price_and_duration(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $legacyService = Service::factory()->create([
            'user_id' => $master->id,
            'price' => 1000.00,
            'duration_minutes' => 60,
        ]);

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'service_id' => $legacyService->id,
            'start_time' => Carbon::tomorrow('Europe/Moscow')->setTime(10, 0)->utc(),
            'status' => 'booked',
        ]);

        $this->assertNull($appointment->price);
        $this->assertNull($appointment->duration);

        $masterService = MasterService::factory()->forMaster($master)->create([
            'price_override' => 1000.00,
            'duration_override' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
        $this->bookingService->createAppointment(
            $master,
            $masterService,
            $tomorrow,
            '11:00',
            'admin',
            null,
        );

        $newAppointment = Appointment::where('id', '!=', $appointment->id)->first();
        $this->assertNotNull($newAppointment->price);
        $this->assertNotNull($newAppointment->duration);
    }

    public function test_snapshot_is_written_on_creation(): void
    {
        $master = $this->createMasterWithSchedule();

        $service = MasterService::factory()->forMaster($master)->create([
            'price_override' => 1500.50,
            'duration_override' => 90,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow,
            '10:00',
            'admin',
        );

        $this->assertSame('1500.50', $appointment->price);
        $this->assertSame(90, $appointment->duration);
    }

    public function test_snapshot_does_not_change_when_service_price_changes(): void
    {
        $master = $this->createMasterWithSchedule();

        $service = MasterService::factory()->forMaster($master)->create([
            'price_override' => 1000.00,
            'duration_override' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow,
            '10:00',
            'admin',
        );

        $this->assertSame('1000.00', $appointment->price);

        $service->update(['price_override' => 2000.00, 'duration_override' => 120]);

        $appointment->refresh();

        $this->assertSame('1000.00', $appointment->price);
        $this->assertSame(60, $appointment->duration);

        $calendar = $appointment->toCalendarArray();
        $this->assertSame(1000.0, $calendar['price']);
        $this->assertSame(60, $calendar['duration']);
    }

    public function test_fallback_to_service_when_snapshot_is_null(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'price' => 500.00,
            'duration_minutes' => 45,
        ]);

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'service_id' => $service->id,
            'start_time' => Carbon::tomorrow('Europe/Moscow')->setTime(10, 0)->utc(),
            'status' => 'booked',
        ]);

        $calendar = $appointment->toCalendarArray();

        // No master_service_id → fallback returns defaults (no live service to fall back to)
        $this->assertSame(0.0, $calendar['price']);
        $this->assertSame(0, $calendar['duration']);
    }

    public function test_solo_master_creates_appointment_with_snapshot(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
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

        $workspace = Workspace::create(['name' => 'Solo WS', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Соло-стрижка', 'base_price' => 2000.00, 'base_duration' => 120]);
        $service = MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'price_override' => 2000.00, 'duration_override' => 120, 'is_active' => true]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow,
            '10:00',
            'telegram',
        );

        $this->assertNotNull($appointment->id);
        $this->assertSame('2000.00', $appointment->price);
        $this->assertSame(120, $appointment->duration);
        $this->assertTrue($master->isSolo());
    }
}
