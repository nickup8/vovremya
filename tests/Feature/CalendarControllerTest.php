<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithWorkspace(): User
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        return $owner;
    }

    public function test_master_sees_self_in_masters_list(): void
    {
        $owner = $this->createOwnerWithWorkspace();
        $master = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
            'role' => 'master',
        ]);

        $response = $this->actingAs($master, 'web')
            ->get('/admin/calendar');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('masters', 1)
            ->where('masters.0.id', $master->id)
            ->where('masters.0.name', $master->name)
        );
    }

    public function test_owner_sees_all_masters(): void
    {
        $owner = $this->createOwnerWithWorkspace();
        $master1 = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
            'role' => 'master',
        ]);
        $master2 = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
            'role' => 'master',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->get('/admin/calendar');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('masters', 3)
        );
    }

    public function test_master_without_hours_gets_them_on_calendar_load(): void
    {
        $owner = $this->createOwnerWithWorkspace();
        $master = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
            'role' => 'master',
        ]);

        DB::table('working_hours')->where('user_id', $master->id)->delete();
        $this->assertSame(0, $master->workingHours()->count());

        $this->actingAs($master, 'web')
            ->get('/admin/calendar');

        $this->assertSame(7, $master->fresh()->workingHours()->count());
    }

    public function test_master_with_hours_no_duplicates(): void
    {
        $owner = $this->createOwnerWithWorkspace();
        $master = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
            'role' => 'master',
        ]);

        $this->assertSame(7, $master->workingHours()->count());

        $this->actingAs($master, 'web')
            ->get('/admin/calendar');

        $this->actingAs($master, 'web')
            ->get('/admin/calendar');

        $this->assertSame(7, $master->workingHours()->count());
    }
}
