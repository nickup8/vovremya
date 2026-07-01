<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingFlowStatusTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(BookingService::class);
    }

    private function createMaster(array $settings = []): User
    {
        $master = User::factory()->master()->create([
            'settings' => array_merge([
                'timezone' => 'Europe/Moscow',
                'timezone_confirmed' => true,
            ], $settings),
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

    #[Test]
    public function prepayment_custom_creates_pending_payment_status(): void
    {
        $master = $this->createMaster([
            'booking_flow_type' => 'prepayment_custom',
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            $tomorrow->format('H:i'),
            'telegram',
        );

        $this->assertEquals(AppointmentStatus::PendingPayment, $appointment->status);
    }

    #[Test]
    public function free_verification_creates_booked_status(): void
    {
        $master = $this->createMaster([
            'booking_flow_type' => 'free_verification',
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            $tomorrow->format('H:i'),
            'telegram',
        );

        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);
    }

    #[Test]
    public function default_booking_flow_type_creates_booked_status(): void
    {
        $master = $this->createMaster();

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            $tomorrow->format('H:i'),
            'telegram',
        );

        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);
    }

    #[Test]
    public function manual_appointment_always_creates_booked_status(): void
    {
        $master = $this->createMaster([
            'booking_flow_type' => 'prepayment_custom',
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $result = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            $tomorrow->format('H:i'),
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(AppointmentStatus::Booked, $result['appointment']->status);
    }

    #[Test]
    public function explicit_status_overrides_auto_detection(): void
    {
        $master = $this->createMaster([
            'booking_flow_type' => 'prepayment_custom',
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $appointment = $this->bookingService->createAppointment(
            $master,
            $service,
            $tomorrow->format('Y-m-d'),
            $tomorrow->format('H:i'),
            'telegram',
            null,
            AppointmentStatus::Booked,
        );

        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);
    }

    #[Test]
    public function paid_as_initial_status_throws_invalid_argument(): void
    {
        $master = $this->createMaster();
        $service = Service::factory()->create(['user_id' => $master->id, 'duration_minutes' => 60]);
        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create appointment with status [paid]');

        $this->bookingService->createAppointment(
            $master, $service,
            $tomorrow->format('Y-m-d'), $tomorrow->format('H:i'),
            'telegram', null, AppointmentStatus::Paid,
        );
    }

    #[Test]
    public function cancelled_as_initial_status_throws_invalid_argument(): void
    {
        $master = $this->createMaster();
        $service = Service::factory()->create(['user_id' => $master->id, 'duration_minutes' => 60]);
        $tomorrow = Carbon::tomorrow('Europe/Moscow')->setTime(10, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create appointment with status [cancelled]');

        $this->bookingService->createAppointment(
            $master, $service,
            $tomorrow->format('Y-m-d'), $tomorrow->format('H:i'),
            'telegram', null, AppointmentStatus::Cancelled,
        );
    }
}
