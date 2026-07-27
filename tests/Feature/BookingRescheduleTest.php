<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(BookingService::class);
    }

    private function createMasterInWorkspace(Workspace $workspace): User
    {
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

    private function tomorrow10amMoscow(): Carbon
    {
        return Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);
    }

    #[Test]
    public function reschedule_same_master_keeps_master_id(): void
    {
        $workspace = Workspace::create([
            'name' => 'Test Salon',
            'owner_id' => User::factory()->create()->id,
        ]);

        $master = $this->createMasterInWorkspace($workspace);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $this->tomorrow10amMoscow()->format('Y-m-d'),
            $this->tomorrow10amMoscow()->format('H:i'),
            'admin',
            null,
            AppointmentStatus::Booked,
        );

        $this->assertEquals($master->id, $appointment->master_id);

        $newTime = $this->tomorrow10amMoscow()->copy()->setTime(15, 0);

        $result = $this->bookingService->rescheduleAppointment(
            $appointment,
            $newTime->format('Y-m-d'),
            $newTime->format('H:i'),
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($master->id, $result['appointment']->master_id);
    }

    #[Test]
    public function reschedule_changes_master_id(): void
    {
        $workspace = Workspace::create([
            'name' => 'Test Salon',
            'owner_id' => User::factory()->create()->id,
        ]);

        $masterA = $this->createMasterInWorkspace($workspace);
        $masterB = $this->createMasterInWorkspace($workspace);

        $service = Service::factory()->create([
            'user_id' => $masterA->id,
            'duration_minutes' => 60,
        ]);

        $appointment = $this->bookingService->createAppointment(
            $masterA,
            $service,
            $this->tomorrow10amMoscow()->format('Y-m-d'),
            $this->tomorrow10amMoscow()->format('H:i'),
            'admin',
            null,
            AppointmentStatus::Booked,
        );

        $this->assertEquals($masterA->id, $appointment->master_id);

        $newTime = $this->tomorrow10amMoscow()->copy()->setTime(14, 0);

        $result = $this->bookingService->rescheduleAppointment(
            $appointment,
            $newTime->format('Y-m-d'),
            $newTime->format('H:i'),
            false,
            false,
            $masterB->id,
        );

        $this->assertTrue($result['success']);
        $this->assertEquals($masterB->id, $result['appointment']->master_id);

        $this->assertEquals(
            $newTime->format('H:i'),
            Carbon::parse($result['appointment']->start_time)
                ->timezone('Europe/Moscow')
                ->format('H:i'),
        );
    }

    #[Test]
    public function reschedule_with_foreign_workspace_throws_403(): void
    {
        $workspaceA = Workspace::create([
            'name' => 'Salon A',
            'owner_id' => User::factory()->create()->id,
        ]);
        $workspaceB = Workspace::create([
            'name' => 'Salon B',
            'owner_id' => User::factory()->create()->id,
        ]);

        $masterA = $this->createMasterInWorkspace($workspaceA);
        $masterB = $this->createMasterInWorkspace($workspaceB);

        $service = Service::factory()->create([
            'user_id' => $masterA->id,
            'duration_minutes' => 60,
        ]);

        $appointment = $this->bookingService->createAppointment(
            $masterA,
            $service,
            $this->tomorrow10amMoscow()->format('Y-m-d'),
            $this->tomorrow10amMoscow()->format('H:i'),
            'admin',
            null,
            AppointmentStatus::Booked,
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Мастер из другого воркспейса');

        $this->bookingService->rescheduleAppointment(
            $appointment,
            $this->tomorrow10amMoscow()->format('Y-m-d'),
            '14:00',
            false,
            false,
            $masterB->id,
        );
    }
}
