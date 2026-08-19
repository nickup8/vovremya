<?php

namespace Tests\Feature\Settings;

use App\Models\BlockedTime;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamResourceManagementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspaceA;

    private Workspace $workspaceB;

    private User $ownerA;

    private User $adminA;

    private User $staff1;

    private User $staff2;

    private User $masterB;

    protected function setUp(): void
    {
        parent::setUp();

        // Workspace A — owner first (owner_id NOT NULL)
        $this->ownerA = User::factory()->master()->create();
        DB::table('users')->where('id', $this->ownerA->id)->update(['role' => 'owner']);

        $this->workspaceA = Workspace::create([
            'name' => 'Salon A',
            'owner_id' => $this->ownerA->id,
        ]);

        $this->ownerA->update(['workspace_id' => $this->workspaceA->id]);

        $this->adminA = User::factory()->master()->create([
            'workspace_id' => $this->workspaceA->id,
        ]);
        DB::table('users')->where('id', $this->adminA->id)->update(['role' => 'admin']);

        $this->staff1 = User::factory()->master()->create([
            'workspace_id' => $this->workspaceA->id,
        ]);
        DB::table('users')->where('id', $this->staff1->id)->update(['role' => 'staff']);

        $this->staff2 = User::factory()->master()->create([
            'workspace_id' => $this->workspaceA->id,
        ]);
        DB::table('users')->where('id', $this->staff2->id)->update(['role' => 'staff']);

        // Workspace B
        $masterBOwner = User::factory()->master()->create();
        DB::table('users')->where('id', $masterBOwner->id)->update(['role' => 'owner']);

        $this->workspaceB = Workspace::create([
            'name' => 'Salon B',
            'owner_id' => $masterBOwner->id,
        ]);

        $this->masterB = User::factory()->master()->create([
            'workspace_id' => $this->workspaceB->id,
        ]);
        DB::table('users')->where('id', $this->masterB->id)->update(['role' => 'staff']);

        $masterBOwner->update(['workspace_id' => $this->workspaceB->id]);
    }

    // ═══════════════ WORKING HOURS ═══════════════

    public function test_owner_updates_staff1s_working_hours(): void
    {
        $response = $this->actingAs($this->ownerA)
            ->put('/admin/working-hours', [
                'master_id' => $this->staff1->id,
                'slot_interval' => 30,
                'working_hours' => [
                    ['day_of_week' => 1, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 2, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 3, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 4, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 5, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '13:00', 'break_end_time' => '14:00'],
                    ['day_of_week' => 6, 'is_working' => false],
                    ['day_of_week' => 0, 'is_working' => false],
                ],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('working_hours', [
            'user_id' => $this->staff1->id,
            'day_of_week' => 1,
            'is_working' => true,
        ]);
    }

    public function test_admin_cannot_update_working_hours_for_master_from_other_workspace(): void
    {
        $response = $this->actingAs($this->adminA)
            ->put('/admin/working-hours', [
                'master_id' => $this->masterB->id,
                'slot_interval' => 30,
                'working_hours' => [
                    ['day_of_week' => 1, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00'],
                    ['day_of_week' => 2, 'is_working' => false],
                    ['day_of_week' => 3, 'is_working' => false],
                    ['day_of_week' => 4, 'is_working' => false],
                    ['day_of_week' => 5, 'is_working' => false],
                    ['day_of_week' => 6, 'is_working' => false],
                    ['day_of_week' => 0, 'is_working' => false],
                ],
            ]);

        $response->assertStatus(404);
    }

    // ═══════════════ BLOCKED TIMES ═══════════════

    public function test_owner_adds_blocked_time_for_staff1(): void
    {
        $response = $this->actingAs($this->ownerA)
            ->post('/admin/blocked-times', [
                'master_id' => $this->staff1->id,
                'start_datetime' => now()->addDays(1)->setTime(10, 0),
                'end_datetime' => now()->addDays(1)->setTime(12, 0),
                'reason' => 'Другое',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('blocked_times', [
            'user_id' => $this->staff1->id,
        ]);
    }

    public function test_staff_deletes_own_blocked_time(): void
    {
        $blockedTime = BlockedTime::factory()->create(['user_id' => $this->staff1->id]);

        $response = $this->actingAs($this->staff1)
            ->delete("/admin/blocked-times/{$blockedTime->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('blocked_times', ['id' => $blockedTime->id]);
    }

    public function test_admin_cannot_delete_blocked_time_from_other_workspace(): void
    {
        $blockedTime = BlockedTime::factory()->create(['user_id' => $this->masterB->id]);

        $response = $this->actingAs($this->adminA)
            ->delete("/admin/blocked-times/{$blockedTime->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('blocked_times', ['id' => $blockedTime->id]);
    }
}
