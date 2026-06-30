<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SlotTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithWorkingHours(string $timezone = 'Europe/Moscow'): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => $timezone, 'timezone_confirmed' => true],
            'slot_interval' => 30,
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::where('user_id', $master->id)
                ->where('day_of_week', $day)
                ->update([
                    'start_time' => '09:00',
                    'end_time' => '19:00',
                    'is_working' => true,
                    'break_start_time' => null,
                    'break_end_time' => null,
                ]);
        }

        return $master;
    }

    public function test_first_slot_is_09_for_moscow_master_on_free_day(): void
    {
        $master = $this->createMasterWithWorkingHours('Europe/Moscow');

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $date = Carbon::today()->addDays(3)->toDateString();

        $slots = app(BookingService::class)->getAvailableSlots($master, $service, $date);

        $this->assertNotEmpty($slots, 'Должен быть хотя бы один слот');
        $this->assertEquals('09:00', $slots[0], 'Первый слот должен быть 09:00');
    }

    public function test_first_slot_is_09_for_utc_master_on_free_day(): void
    {
        $master = $this->createMasterWithWorkingHours('UTC');

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $date = Carbon::today()->addDays(3)->toDateString();

        $slots = app(BookingService::class)->getAvailableSlots($master, $service, $date);

        $this->assertNotEmpty($slots);
        $this->assertEquals('09:00', $slots[0]);
    }

    public function test_past_slots_are_filtered_by_master_timezone(): void
    {
        $master = $this->createMasterWithWorkingHours('Europe/Moscow');

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 30,
        ]);

        $today = Carbon::today();

        Carbon::setTestNow(Carbon::today()->timezone('Europe/Moscow')->setTime(10, 47));

        $slots = app(BookingService::class)->getAvailableSlots($master, $service, $today->toDateString());

        $this->assertNotEmpty($slots);
        $this->assertNotContains('09:00', $slots, '09:00 MSK должно быть в прошлом при now()=10:47 MSK');
        $this->assertContains('11:00', $slots, '11:00 MSK должно быть доступно');

        Carbon::setTestNow();
    }

    public function test_blocked_time_affects_slots_in_master_timezone(): void
    {
        $master = $this->createMasterWithWorkingHours('Europe/Moscow');

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $today = Carbon::today()->timezone('Europe/Moscow');

        \App\Models\BlockedTime::create([
            'user_id' => $master->id,
            'start_datetime' => $today->copy()->setTime(10, 0)->timezone('UTC'),
            'end_datetime' => $today->copy()->setTime(12, 0)->timezone('UTC'),
            'reason' => \App\Enums\BlockedTimeReason::Personal,
        ]);

        $slots = app(BookingService::class)->getAvailableSlots($master, $service, $today->toDateString());

        $this->assertNotContains('10:00', $slots, '10:00 MSK должно быть заблокировано');
        $this->assertNotContains('11:00', $slots, '11:00 MSK должно быть заблокировано');
        $this->assertContains('12:00', $slots, '12:00 MSK должно быть доступно');
    }

    public function test_break_affects_slots_in_master_timezone(): void
    {
        $master = $this->createMasterWithWorkingHours('Europe/Moscow');

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
        ]);

        $today = Carbon::today()->timezone('Europe/Moscow');

        WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $today->dayOfWeek)
            ->update([
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
            ]);

        $slots = app(BookingService::class)->getAvailableSlots($master, $service, $today->toDateString());

        $this->assertNotContains('12:30', $slots, '12:30 MSK пересекает обед 13:00-14:00');
        $this->assertNotContains('13:00', $slots, '13:00 MSK попадает на обед');
        $this->assertContains('14:00', $slots, '14:00 MSK должно быть доступно после обеда');
    }
}
