<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingDefaultStatusTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(BookingService::class);
    }

    private function createMasterWithSchedule(string $bookingFlowType): User
    {
        $master = User::factory()->master()->create([
            'settings' => [
                'timezone' => 'Europe/Moscow',
                'timezone_confirmed' => true,
                'booking_flow_type' => $bookingFlowType,
            ],
        ]);

        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => Carbon::now()->dayOfWeek],
            ['start_time' => '09:00', 'end_time' => '18:00', 'is_working' => true],
        );

        return $master;
    }

    public function test_default_appointment_is_booked_regardless_of_legacy_booking_flow_type(): void
    {
        $master = $this->createMasterWithSchedule('prepayment_custom');

        $catalog = ServiceCatalog::factory()->create(['workspace_id' => $master->workspace_id, 'is_active' => true]);
        $service = MasterService::factory()->forMaster($master)->create([
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
        $client = Client::factory()->create();

        $date = Carbon::tomorrow()->format('Y-m-d');
        $time = '10:00';

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $date,
            $time,
            'web',
            $client->id,
        );

        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
    }

    public function test_default_appointment_is_booked_for_free_verification(): void
    {
        $master = $this->createMasterWithSchedule('free_verification');

        $catalog = ServiceCatalog::factory()->create(['workspace_id' => $master->workspace_id, 'is_active' => true]);
        $service = MasterService::factory()->forMaster($master)->create([
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
        $client = Client::factory()->create();

        $date = Carbon::tomorrow()->format('Y-m-d');
        $time = '10:00';

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $date,
            $time,
            'web',
            $client->id,
        );

        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
    }

    public function test_explicit_status_is_preserved(): void
    {
        $master = $this->createMasterWithSchedule('free_verification');

        $catalog = ServiceCatalog::factory()->create(['workspace_id' => $master->workspace_id, 'is_active' => true]);
        $service = MasterService::factory()->forMaster($master)->create([
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
        $client = Client::factory()->create();

        $date = Carbon::tomorrow()->format('Y-m-d');
        $time = '10:00';

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $date,
            $time,
            'web',
            $client->id,
            AppointmentStatus::PendingPayment,
        );

        $this->assertSame(AppointmentStatus::PendingPayment, $appointment->status);
    }
}
