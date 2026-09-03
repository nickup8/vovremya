<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarAvailableSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithWorkspace(): array
    {
        $owner = User::factory()->master()->create([
            'slot_interval' => 30,
        ]);
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        WorkingHour::where('user_id', $owner->id)->delete();

        // Mon-Fri 09:00-18:00, break 13:00-14:00
        foreach ([1, 2, 3, 4, 5] as $dow) {
            WorkingHour::create([
                'user_id' => $owner->id,
                'day_of_week' => $dow,
                'is_working' => true,
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
            ]);
        }

        // Saturday: working 10:00-15:00, no break
        WorkingHour::create([
            'user_id' => $owner->id,
            'day_of_week' => 6,
            'is_working' => true,
            'start_time' => '10:00',
            'end_time' => '15:00',
            'break_start_time' => null,
            'break_end_time' => null,
        ]);

        // Sunday: not working
        WorkingHour::create([
            'user_id' => $owner->id,
            'day_of_week' => 0,
            'is_working' => false,
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 60,
            'is_active' => true,
        ]);

        $service = MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        return [$owner, $workspace, $service];
    }

    private function nextMonday(): Carbon
    {
        $date = Carbon::now($this->ownerTz ?? 'Europe/Moscow')->next(Carbon::MONDAY);

        return $date;
    }

    private ?string $ownerTz = 'Europe/Moscow';

    public function test_working_day_returns_free_slots(): void
    {
        [$owner, , $service] = $this->createOwnerWithWorkspace();
        $date = $this->nextMonday()->format('Y-m-d');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/admin/calendar/available-slots?date={$date}&service_id={$service->id}");

        $response->assertOk();
        $data = $response->json();

        $this->assertNotEmpty($data['freeSlots']);
        $this->assertContains('09:00', $data['freeSlots']);
        $this->assertContains('12:00', $data['freeSlots']);
        // 13:00 should be excluded (break for 60-min service: 13:00-14:00 overlaps)
        $this->assertNotContains('13:00', $data['freeSlots']);
    }

    public function test_outside_slots_before_and_after_working_hours(): void
    {
        [$owner, , $service] = $this->createOwnerWithWorkspace();
        $date = $this->nextMonday()->format('Y-m-d');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/admin/calendar/available-slots?date={$date}&service_id={$service->id}");

        $response->assertOk();
        $data = $response->json();

        $this->assertNotEmpty($data['outsideSlots']);
        // Before 09:00
        $this->assertContains('07:00', $data['outsideSlots']);
        $this->assertContains('08:00', $data['outsideSlots']);
        // After 18:00
        $this->assertContains('18:00', $data['outsideSlots']);
        $this->assertContains('19:00', $data['outsideSlots']);
        // During working hours should NOT be in outside
        $this->assertNotContains('10:00', $data['outsideSlots']);
        $this->assertNotContains('14:00', $data['outsideSlots']);
    }

    public function test_existing_appointment_excludes_outside_slot(): void
    {
        [$owner, , $service] = $this->createOwnerWithWorkspace();
        $date = $this->nextMonday();
        $tz = $owner->getTimezone();

        // Book 06:00-07:00 (outside zone, before work)
        $startTime = Carbon::parse($date->format('Y-m-d') . ' 06:00', $tz);
        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => null,
            'service_name' => 'Стрижка',
            'price' => 1000,
            'duration' => 60,
            'start_time' => $startTime->timezone('UTC'),
            'status' => 'booked',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/admin/calendar/available-slots?date={$date->format('Y-m-d')}&service_id={$service->id}");

        $response->assertOk();
        $data = $response->json();

        // 06:00 should be excluded (appointment 06:00-07:00 blocks it)
        $this->assertNotContains('06:00', $data['outsideSlots']);
        // 05:30 should still be available (ends at 06:30, doesn't overlap 06:00-07:00? Actually 05:30+60=06:30 which overlaps 06:00-07:00)
        // 05:00 should be available (05:00+60=06:00, slot end equals appointment start, no overlap)
        $this->assertContains('05:00', $data['outsideSlots']);
    }

    public function test_blocked_time_excludes_outside_slot(): void
    {
        [$owner, , $service] = $this->createOwnerWithWorkspace();
        $date = $this->nextMonday();
        $tz = $owner->getTimezone();

        // Block 19:00-20:00 (outside zone, after work)
        BlockedTime::create([
            'user_id' => $owner->id,
            'start_datetime' => Carbon::parse($date->format('Y-m-d') . ' 19:00', $tz)->timezone('UTC'),
            'end_datetime' => Carbon::parse($date->format('Y-m-d') . ' 20:00', $tz)->timezone('UTC'),
            'reason' => 'Другое',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/admin/calendar/available-slots?date={$date->format('Y-m-d')}&service_id={$service->id}");

        $response->assertOk();
        $data = $response->json();

        // 19:00 should be excluded (blocked 19:00-20:00)
        $this->assertNotContains('19:00', $data['outsideSlots']);
        // 18:00 should still be available (18:00+60=19:00, slot end equals block start, no overlap)
        $this->assertContains('18:00', $data['outsideSlots']);
        // 20:00 should be available (after block ends)
        $this->assertContains('20:00', $data['outsideSlots']);
    }

    public function test_day_off_returns_all_day_outside_slots(): void
    {
        [$owner, , $service] = $this->createOwnerWithWorkspace();

        // Find next Sunday (day_of_week = 0, not working)
        $date = Carbon::now($owner->getTimezone())->next(Carbon::SUNDAY)->format('Y-m-d');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/admin/calendar/available-slots?date={$date}&service_id={$service->id}");

        $response->assertOk();
        $data = $response->json();

        $this->assertEmpty($data['freeSlots']);
        $this->assertNotEmpty($data['outsideSlots']);
        $this->assertContains('00:00', $data['outsideSlots']);
        $this->assertContains('09:00', $data['outsideSlots']);
        $this->assertContains('18:00', $data['outsideSlots']);
    }

    public function test_foreign_service_returns_403(): void
    {
        [$owner] = $this->createOwnerWithWorkspace();

        // Create another workspace with its own service
        $otherOwner = User::factory()->master()->create(['slot_interval' => 30]);
        $otherWorkspace = Workspace::create([
            'name' => 'Other Studio',
            'owner_id' => $otherOwner->id,
        ]);
        $otherOwner->update(['workspace_id' => $otherWorkspace->id]);

        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $otherWorkspace->id,
            'title' => 'Other Service',
            'base_price' => 500,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $otherService = MasterService::create([
            'master_id' => $otherOwner->id,
            'catalog_id' => $otherCatalog->id,
            'is_active' => true,
        ]);

        $date = $this->nextMonday()->format('Y-m-d');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/admin/calendar/available-slots?date={$date}&service_id={$otherService->id}");

        $response->assertStatus(403);
    }
}
