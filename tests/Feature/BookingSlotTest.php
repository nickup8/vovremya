<?php

namespace Tests\Feature;

use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingSlotTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(BookingService::class);
    }

    private function createMasterWithSchedule(int $dayOfWeek): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

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
    public function successful_slot_returns_ok(): void
    {
        $master = $this->createMasterWithSchedule(
            $this->tomorrow10amMoscow()->dayOfWeek
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $result = $this->bookingService->checkSlot(
            $master,
            $this->tomorrow10amMoscow(),
            $service->duration_minutes,
        );

        $this->assertSame('ok', $result['status']);
    }

    #[Test]
    public function slot_taken_returns_error(): void
    {
        $master = $this->createMasterWithSchedule(
            $this->tomorrow10amMoscow()->dayOfWeek
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $client = Client::factory()->create(['user_id' => $master->id]);

        \App\Models\Appointment::factory()
            ->forMaster($master)
            ->forClient($client)
            ->withService($service)
            ->booked()
            ->create([
                'start_time' => $this->tomorrow10amMoscow()->copy()->timezone('UTC'),
            ]);

        $result = $this->bookingService->checkSlot(
            $master,
            $this->tomorrow10amMoscow(),
            $service->duration_minutes,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('slot_taken', $result['error']);
    }

    #[Test]
    public function slot_before_working_hours_returns_error(): void
    {
        $master = $this->createMasterWithSchedule(
            $this->tomorrow10amMoscow()->dayOfWeek
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tooEarly = $this->tomorrow10amMoscow()->copy()->setTime(7, 0);

        $result = $this->bookingService->checkSlot(
            $master,
            $tooEarly,
            $service->duration_minutes,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('outside_working_hours', $result['error']);
    }

    #[Test]
    public function slot_after_working_hours_returns_error(): void
    {
        $master = $this->createMasterWithSchedule(
            $this->tomorrow10amMoscow()->dayOfWeek
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        // 17:30 + 60мин = 18:30 → за пределами 18:00
        $tooLate = $this->tomorrow10amMoscow()->copy()->setTime(17, 30);

        $result = $this->bookingService->checkSlot(
            $master,
            $tooLate,
            $service->duration_minutes,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('outside_working_hours', $result['error']);
    }

    #[Test]
    public function slot_overlapping_lunch_break_returns_error(): void
    {
        $master = $this->createMasterWithSchedule(
            $this->tomorrow10amMoscow()->dayOfWeek
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        // 12:30 + 60мин = 13:30 → пересекает обед 13:00–14:00
        $lunchOverlap = $this->tomorrow10amMoscow()->copy()->setTime(12, 30);

        $result = $this->bookingService->checkSlot(
            $master,
            $lunchOverlap,
            $service->duration_minutes,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('break_intersection', $result['error']);
    }

    #[Test]
    public function slot_inside_blocked_time_returns_error(): void
    {
        $master = $this->createMasterWithSchedule(
            $this->tomorrow10amMoscow()->dayOfWeek
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $tomorrow = $this->tomorrow10amMoscow()->copy()->startOfDay();
        $blockStart = $tomorrow->copy()->setTime(8, 0)->timezone('UTC');
        $blockEnd = $tomorrow->copy()->setTime(12, 0)->timezone('UTC');

        BlockedTime::create([
            'user_id' => $master->id,
            'start_datetime' => $blockStart->format('Y-m-d H:i:s'),
            'end_datetime' => $blockEnd->format('Y-m-d H:i:s'),
            'reason' => 'Другое',
        ]);

        $result = $this->bookingService->checkSlot(
            $master,
            $this->tomorrow10amMoscow(),
            $service->duration_minutes,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('slot_taken', $result['error']);
    }

    #[Test]
    public function day_off_returns_error(): void
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $dayOfWeek = $this->tomorrow10amMoscow()->dayOfWeek;

        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => $dayOfWeek],
            [
                'start_time' => null,
                'end_time' => null,
                'break_start_time' => null,
                'break_end_time' => null,
                'is_working' => false,
            ],
        );

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $result = $this->bookingService->checkSlot(
            $master,
            $this->tomorrow10amMoscow(),
            $service->duration_minutes,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('outside_working_hours', $result['error']);
    }
}
