<?php

namespace Tests\Unit;

use App\Exceptions\CancellationNotAllowedException;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentStatusServiceCancelTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AppointmentStatusService::class);
    }

    public function test_booked_future_no_deadline_can_cancel(): void
    {
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $appointment = Appointment::factory()->booked()->forMaster($master)->create([
            'start_time' => now()->addDays(2),
        ]);

        // Не бросает — можно отменять
        $this->service->assertCanCancel($appointment);
        $this->assertTrue(true);
    }

    public function test_booked_deadline_24h_start_in_48h_can_cancel(): void
    {
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => 24]);
        $appointment = Appointment::factory()->booked()->forMaster($master)->create([
            'start_time' => now()->addHours(48),
        ]);

        $this->service->assertCanCancel($appointment);
        $this->assertTrue(true);
    }

    public function test_booked_deadline_24h_start_in_12h_throws_deadline_passed(): void
    {
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => 24]);
        $appointment = Appointment::factory()->booked()->forMaster($master)->create([
            'start_time' => now()->addHours(12),
        ]);

        $this->expectException(CancellationNotAllowedException::class);

        try {
            $this->service->assertCanCancel($appointment);
        } catch (CancellationNotAllowedException $e) {
            $this->assertSame('deadline_passed', $e->getReason());
            $this->assertSame(24, $e->getDeadlineHours());

            throw $e;
        }
    }

    public function test_cancelled_status_throws_not_cancellable(): void
    {
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $appointment = Appointment::factory()->cancelled()->forMaster($master)->create([
            'start_time' => now()->addDays(2),
        ]);

        $this->expectException(CancellationNotAllowedException::class);

        try {
            $this->service->assertCanCancel($appointment);
        } catch (CancellationNotAllowedException $e) {
            $this->assertSame('not_cancellable', $e->getReason());

            throw $e;
        }
    }

    public function test_past_start_time_throws_not_cancellable(): void
    {
        $master = User::factory()->master()->create(['cancellation_deadline_hours' => null]);
        $appointment = Appointment::factory()->booked()->forMaster($master)->create([
            'start_time' => now()->subHour(),
        ]);

        $this->expectException(CancellationNotAllowedException::class);

        try {
            $this->service->assertCanCancel($appointment);
        } catch (CancellationNotAllowedException $e) {
            $this->assertSame('not_cancellable', $e->getReason());

            throw $e;
        }
    }
}
