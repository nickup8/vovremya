<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingHoursAtomicSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = User::factory()->master()->create([
            'slot_interval' => 30,
        ]);
        $this->actingAs($this->master);
    }

    public function test_invalid_day_prevents_all_saves_including_slot_interval(): void
    {
        $masterId = $this->master->id;

        // UserObserver creates 7 default working hours — update day 0 and 1 to known state
        WorkingHour::where('user_id', $masterId)->where('day_of_week', 0)->update([
            'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00',
            'break_start_time' => '13:00', 'break_end_time' => '14:00',
        ]);
        WorkingHour::where('user_id', $masterId)->where('day_of_week', 1)->update([
            'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00',
            'break_start_time' => '13:00', 'break_end_time' => '14:00',
        ]);

        $originalSlotInterval = $this->master->slot_interval;

        $response = $this->put(route('admin.working-hours.update'), [
            'working_hours' => [
                ['day_of_week' => 0, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['day_of_week' => 1, 'is_working' => true, 'start_time' => '18:00', 'end_time' => '09:00'], // invalid: end <= start
                ['day_of_week' => 2, 'is_working' => true, 'start_time' => '10:00', 'end_time' => '17:00'],
            ],
            'slot_interval' => 15,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('working_hours.1.end_time');

        // Day 0 should NOT be changed (still original hours)
        $day0 = WorkingHour::where('user_id', $masterId)->where('day_of_week', 0)->first();
        $this->assertStringStartsWith('09:00', $day0->start_time);
        $this->assertStringStartsWith('18:00', $day0->end_time);

        // Day 2 was already a default row (from observer), check it was NOT updated
        $day2 = WorkingHour::where('user_id', $masterId)->where('day_of_week', 2)->first();
        // Default has start_time=09:00 — should stay 09:00 (not 10:00)
        $this->assertStringStartsWith('09:00', $day2->start_time);

        // slot_interval should NOT be changed
        $this->master->refresh();
        $this->assertSame($originalSlotInterval, $this->master->slot_interval);
    }
}
