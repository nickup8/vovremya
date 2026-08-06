<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Exceptions\PastAppointmentException;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppointmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CancellationTimeGateTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentStatusService $statusService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statusService = app(AppointmentStatusService::class);
    }

    private function createMasterWithService(): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        return $master;
    }

    private function createClientForMaster(User $master): Client
    {
        return Client::create([
            'user_id' => $master->id,
            'name' => 'Тест Клиент',
            'phone' => '+7900' . fake()->unique()->numerify('#######'),
        ]);
    }

    private function createAppointment(User $master, AppointmentStatus $status, string $startTime, ?Client $client = null): Appointment
    {
        return Appointment::factory()
            ->forMaster($master)
            ->create([
                'status' => $status,
                'start_time' => $startTime,
                'client_id' => $client?->id,
                'service_name' => 'Стрижка',
                'price' => 1000,
                'duration' => 60,
            ]);
    }

    // A: будущая запись → cancel (Client) → успех
    public function test_future_cancel_ok(): void
    {
        $master = $this->createMasterWithService();
        $client = $this->createClientForMaster($master);
        $appointment = $this->createAppointment($master, AppointmentStatus::Booked, Carbon::tomorrow('Europe/Moscow')->setTime(10, 0)->utc(), $client);

        $this->statusService->transition($appointment, AppointmentStatus::Cancelled, $client);

        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertNotNull($appointment->fresh()->cancelled_at);
    }

    // B: прошедшая запись → cancel (Client) → PastAppointmentException
    public function test_past_cancel_blocked_for_client(): void
    {
        $master = $this->createMasterWithService();
        $client = $this->createClientForMaster($master);
        $appointment = $this->createAppointment($master, AppointmentStatus::Booked, Carbon::yesterday('Europe/Moscow')->setTime(10, 0)->utc(), $client);

        $this->expectException(PastAppointmentException::class);
        $this->statusService->transition($appointment, AppointmentStatus::Cancelled, $client);
    }

    // C: прошедшая запись → cancel (система, actor=null) → успех (крон обходит)
    public function test_past_cancel_system_bypass(): void
    {
        $master = $this->createMasterWithService();
        $appointment = $this->createAppointment($master, AppointmentStatus::Booked, Carbon::yesterday('Europe/Moscow')->setTime(11, 0)->utc());

        $this->statusService->transition($appointment, AppointmentStatus::Cancelled);

        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    // D: прошедшая запись → cancel (super_admin) → успех + аудит cancelled_by
    public function test_past_cancel_superadmin_bypass(): void
    {
        $master = $this->createMasterWithService();
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $appointment = $this->createAppointment($master, AppointmentStatus::Booked, Carbon::yesterday('Europe/Moscow')->setTime(12, 0)->utc());

        $this->statusService->transition($appointment, AppointmentStatus::Cancelled, $admin);

        $fresh = $appointment->fresh();
        $this->assertEquals(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertEquals($admin->id, $fresh->cancelled_by);
        $this->assertNotNull($fresh->cancelled_at);
    }

    // E: прошедшая отменённая → воскрешение (Client) → PastAppointmentException
    public function test_past_resurrect_blocked_for_client(): void
    {
        $master = $this->createMasterWithService();
        $client = $this->createClientForMaster($master);
        $appointment = $this->createAppointment($master, AppointmentStatus::Cancelled, Carbon::yesterday('Europe/Moscow')->setTime(13, 0)->utc(), $client);

        $this->expectException(PastAppointmentException::class);
        $this->statusService->transition($appointment, AppointmentStatus::Booked, $client);
    }
}
