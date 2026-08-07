<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppointmentPriceSnapshotTest extends TestCase
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

    public function test_snapshot_written_on_creation(): void
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
        $this->assertSame(60, $appointment->duration);
    }

    public function test_price_immutable_after_service_change(): void
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

        $this->assertSame('1000.00', $appointment->price, 'Price snapshot must NOT change when service price is updated');
        $this->assertSame(60, $appointment->duration, 'Duration snapshot must NOT change when service duration is updated');

        $calendar = $appointment->toCalendarArray();
        $this->assertSame(1000.0, $calendar['price'], 'toCalendarArray must return snapshot price, not new service price');
        $this->assertSame(60, $calendar['duration'], 'toCalendarArray must return snapshot duration, not new service duration');
    }

    public function test_fallback_returns_defaults_when_no_snapshot_and_no_master_service(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'start_time' => Carbon::tomorrow('Europe/Moscow')->setTime(10, 0)->utc(),
            'status' => 'booked',
        ]);

        $this->assertNull($appointment->price);
        $this->assertNull($appointment->duration);

        $calendar = $appointment->toCalendarArray();

        // No snapshot and no master_service → defaults
        $this->assertSame(0.0, $calendar['price']);
        $this->assertSame(0, $calendar['duration']);
    }

    public function test_solo_master_appointment_creation_regression(): void
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

        $this->assertTrue($master->isSolo());

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
    }
}
