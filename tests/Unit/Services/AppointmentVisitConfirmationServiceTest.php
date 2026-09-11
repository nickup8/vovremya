<?php

namespace Tests\Unit\Services;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentVisitConfirmed;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\AppointmentVisitConfirmationService;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppointmentVisitConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentVisitConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AppointmentVisitConfirmationService();
    }

    private function createAppointmentAndClient(string $status = 'booked', ?string $clientConfirmedAt = null): array
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['user_id' => $master->id]);
        $ms = MasterService::factory()->forMaster($master)->create();

        $appointment = Appointment::factory()
            ->forMaster($master)
            ->forClient($client)
            ->withMasterService($ms)
            ->create([
                'status' => $status,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'client_confirmed_at' => $clientConfirmedAt,
            ]);

        return [$appointment, $client, $master];
    }

    public function test_valid_confirmation_sets_client_confirmed_at(): void
    {
        [$appointment, $client] = $this->createAppointmentAndClient();

        $result = $this->service->confirm($appointment, $client);

        $this->assertSame('ok', $result['result']);
        $appointment->refresh();
        $this->assertNotNull($appointment->client_confirmed_at);
    }

    public function test_success_broadcasts_appointment_visit_confirmed(): void
    {
        Event::fake([AppointmentVisitConfirmed::class]);
        [$appointment, $client] = $this->createAppointmentAndClient();

        $this->service->confirm($appointment, $client);

        Event::assertDispatched(AppointmentVisitConfirmed::class);
    }

    public function test_success_triggers_master_notification(): void
    {
        [$appointment, $client, $master] = $this->createAppointmentAndClient();

        $masterId = $master->id;

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')
            ->once()
            ->with(
                \Mockery::on(fn ($m) => $m->id === $masterId),
                \Mockery::type('string'),
            );

        $this->service->confirm($appointment, $client);
    }

    public function test_ownership_mismatch_does_not_update(): void
    {
        [$appointment] = $this->createAppointmentAndClient();
        $otherClient = Client::factory()->create(['user_id' => $appointment->master_id]);

        $result = $this->service->confirm($appointment, $otherClient);

        $this->assertSame('not_found', $result['result']);
        $appointment->refresh();
        $this->assertNull($appointment->client_confirmed_at);
    }

    public function test_status_not_booked_does_not_update(): void
    {
        [$appointment, $client] = $this->createAppointmentAndClient('cancelled');

        $result = $this->service->confirm($appointment, $client);

        $this->assertSame('not_available', $result['result']);
        $appointment->refresh();
        $this->assertNull($appointment->client_confirmed_at);
    }

    public function test_already_confirmed_is_idempotent(): void
    {
        $confirmedAt = now()->subHour()->toDateTimeString();
        [$appointment, $client] = $this->createAppointmentAndClient('booked', $confirmedAt);

        $result = $this->service->confirm($appointment, $client);

        $this->assertSame('already', $result['result']);
        $appointment->refresh();
        $this->assertSame($confirmedAt, $appointment->client_confirmed_at->toDateTimeString());
    }
}
