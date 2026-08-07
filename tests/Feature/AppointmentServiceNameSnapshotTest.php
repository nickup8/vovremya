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

class AppointmentServiceNameSnapshotTest extends TestCase
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

    public function test_service_name_snapshot_written_on_creation(): void
    {
        $master = $this->createMasterWithSchedule();

        $service = MasterService::factory()->forMaster($master)->create();

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow,
            '10:00',
            'admin',
        );

        $this->assertSame($service->catalog->title, $appointment->service_name);
    }

    public function test_service_name_immutable_after_service_rename(): void
    {
        $master = $this->createMasterWithSchedule();

        $service = MasterService::factory()->forMaster($master)->create();

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow,
            '10:00',
            'admin',
        );

        $originalTitle = $service->catalog->title;
        $this->assertSame($originalTitle, $appointment->service_name);

        $service->catalog->update(['title' => $originalTitle . ' PRO']);

        $appointment->refresh();

        $this->assertSame($originalTitle, $appointment->service_name, 'service_name snapshot must NOT change when service title is updated');

        $calendar = $appointment->toCalendarArray();
        $this->assertSame($originalTitle, $calendar['service'], 'toCalendarArray must return snapshot service_name, not new service title');
    }

    public function test_service_name_fallback_returns_default_when_no_snapshot_and_no_master_service(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'start_time' => Carbon::tomorrow('Europe/Moscow')->setTime(10, 0)->utc(),
            'status' => 'booked',
        ]);

        $this->assertNull($appointment->service_name);

        $calendar = $appointment->toCalendarArray();

        // No snapshot, no master_service → default
        $this->assertSame('Услуга удалена', $calendar['service']);
    }

    public function test_solo_master_regression(): void
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
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Массаж', 'base_price' => 2000.00, 'base_duration' => 120]);
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
        $this->assertSame('Массаж', $appointment->service_name);
        $this->assertSame('2000.00', $appointment->price);
        $this->assertSame(120, $appointment->duration);

        $calendar = $appointment->toCalendarArray();
        $this->assertSame('Массаж', $calendar['service']);
    }
}
