<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Models\BlockedTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePageTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'slot_interval' => 30,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);
        $this->ws = Workspace::create(['name' => 'WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    public function test_schedule_page_is_displayed(): void
    {
        $response = $this->actingAs($this->master)
            ->get(route('admin.schedule'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/schedule')
        );
    }

    public function test_schedule_page_has_required_props(): void
    {
        WorkingHour::updateOrCreate(
            ['user_id' => $this->master->id, 'day_of_week' => 1],
            ['start_time' => '09:00', 'end_time' => '18:00', 'is_working' => true],
        );

        $response = $this->actingAs($this->master)
            ->get(route('admin.schedule'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/schedule')
            ->has('workingHours')
            ->has('blockedTimes')
            ->has('profile')
            ->where('profile.id', $this->master->id)
            ->where('profile.slot_interval', 30)
        );
    }

    public function test_schedule_slot_interval_belongs_to_target_master(): void
    {
        $otherMaster = User::factory()->master()->create([
            'slot_interval' => 60,
            'workspace_id' => $this->ws->id,
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.schedule', ['master_id' => $otherMaster->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('profile.id', $otherMaster->id)
            ->where('profile.slot_interval', 60)
        );
    }

    public function test_cannot_access_master_from_other_workspace(): void
    {
        $otherWs = Workspace::create(['name' => 'Other', 'owner_id' => $this->master->id]);
        $otherMaster = User::factory()->master()->create([
            'workspace_id' => $otherWs->id,
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.schedule', ['master_id' => $otherMaster->id]));

        $response->assertStatus(404);
    }

    public function test_guest_is_redirected(): void
    {
        $response = $this->get(route('admin.schedule'));

        $response->assertRedirect('/login');
    }
}
