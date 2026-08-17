<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualAppointmentWarningTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(BookingService::class);
    }

    private function createMasterWithBreak(): User
    {
        $workspace = Workspace::create([
            'name' => 'Test Salon',
            'owner_id' => User::factory()->create()->id,
        ]);

        $master = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $dayOfWeek = Carbon::tomorrow('Europe/Moscow')->dayOfWeek;

        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => $dayOfWeek],
            [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
                'is_working' => true,
            ],
        );

        return $master;
    }

    private function tomorrowAt(string $time): string
    {
        return Carbon::tomorrow('Europe/Moscow')->format('Y-m-d');
    }

    #[Test]
    public function create_manual_appointment_without_warning_succeeds(): void
    {
        $master = $this->createMasterWithBreak();

        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $client = Client::factory()->create(['user_id' => $master->id]);

        $result = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $this->tomorrowAt('10:00'),
            '10:00',
            false,
            false,
            $client->id,
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['appointment']);
        $this->assertEquals(AppointmentStatus::Booked, $result['appointment']->status);
    }

    #[Test]
    public function create_manual_appointment_with_break_intersection_returns_warning(): void
    {
        $master = $this->createMasterWithBreak();

        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $client = Client::factory()->create(['user_id' => $master->id]);

        // 13:00-14:00 is the break, 60-min appointment at 13:00 overlaps
        $result = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $this->tomorrowAt('13:00'),
            '13:00',
            false,
            false,
            $client->id,
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('break_intersection', $result['error']);
    }

    #[Test]
    public function create_manual_appointment_with_ignore_warnings_creates_despite_break(): void
    {
        $master = $this->createMasterWithBreak();

        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $client = Client::factory()->create(['user_id' => $master->id]);

        // First attempt returns warning
        $first = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $this->tomorrowAt('13:00'),
            '13:00',
            false,
            false,
            $client->id,
        );

        $this->assertFalse($first['success']);
        $this->assertEquals('break_intersection', $first['error']);

        // Second attempt with ignore_warnings=true creates the appointment
        $second = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $this->tomorrowAt('13:00'),
            '13:00',
            true, // ignoreWarnings
            false,
            $client->id,
        );

        $this->assertTrue($second['success']);
        $this->assertNotNull($second['appointment']);
        $this->assertEquals(AppointmentStatus::Booked, $second['appointment']->status);
    }

    #[Test]
    public function ignore_warnings_does_not_bypass_hard_slot_conflict(): void
    {
        $master = $this->createMasterWithBreak();

        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $client = Client::factory()->create(['user_id' => $master->id]);

        // Create first appointment at 10:00
        $first = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $this->tomorrowAt('10:00'),
            '10:00',
            false,
            false,
            $client->id,
        );

        $this->assertTrue($first['success']);

        // Try to create overlapping appointment at 10:30 with ignore_warnings=true
        // This should fail because slot_taken is a hard conflict, not a warning
        $second = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $this->tomorrowAt('10:00'),
            '10:00',
            true, // ignoreWarnings
            false,
            $client->id,
        );

        $this->assertFalse($second['success']);
        $this->assertEquals('slot_taken', $second['error']);
    }
}
