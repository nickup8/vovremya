<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\PlatformPermission;
use App\Models\PlatformAdminAccess;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPermissionTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $startPlan;
    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'max_appointments_per_month' => 7, 'max_masters' => 1,
            'features' => ['calendar'], 'is_active' => true,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => ['unlimited_appointments'], 'is_active' => true,
        ]);
    }

    private function createLimitedAdmin(array $permissions): User
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $user->id,
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        return $user;
    }

    // ── A. ROOT BYPASS ──

    public function test_root_can_access_dashboard(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->get(route('super_admin.dashboard'))
            ->assertOk();
    }

    public function test_root_can_access_users_plans_audit(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)->get(route('super_admin.users'))->assertOk();
        $this->actingAs($root)->get(route('super_admin.plans'))->assertOk();
        $this->actingAs($root)->get(route('super_admin.audit'))->assertOk();
    }

    public function test_root_can_perform_mutations(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.block', $target))
            ->assertRedirect();
    }

    // ── B. NORMAL MASTER ──

    public function test_normal_user_without_access_gets_403(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('super_admin.dashboard'))
            ->assertStatus(403);
    }

    public function test_workspace_owner_role_does_not_grant_platform_access(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        Workspace::create(['name' => 'W', 'owner_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('super_admin.dashboard'))
            ->assertStatus(403);
    }

    // ── C. LIMITED ADMIN READ ──

    public function test_limited_admin_with_dashboard_view_can_access(): void
    {
        $admin = $this->createLimitedAdmin(['dashboard.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.dashboard'))
            ->assertOk();
    }

    public function test_dashboard_view_does_not_grant_users_access(): void
    {
        $admin = $this->createLimitedAdmin(['dashboard.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.users'))
            ->assertStatus(403);
    }

    public function test_users_view_grants_users_access(): void
    {
        $admin = $this->createLimitedAdmin(['users.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.users'))
            ->assertOk();
    }

    public function test_plans_view_grants_plans_access(): void
    {
        $admin = $this->createLimitedAdmin(['plans.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.plans'))
            ->assertOk();
    }

    public function test_audit_view_grants_audit_access(): void
    {
        $admin = $this->createLimitedAdmin(['audit.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.audit'))
            ->assertOk();
    }

    // ── D. LIMITED ADMIN WRITE ──

    public function test_users_block_permission_allows_block(): void
    {
        $admin = $this->createLimitedAdmin(['users.block']);
        $target = User::factory()->master()->create();

        $this->actingAs($admin)
            ->post(route('super_admin.block', $target))
            ->assertRedirect();
    }

    public function test_users_view_without_users_block_blocks_action(): void
    {
        $admin = $this->createLimitedAdmin(['users.view']);
        $target = User::factory()->master()->create();

        $this->actingAs($admin)
            ->post(route('super_admin.block', $target))
            ->assertStatus(403);
    }

    public function test_subscriptions_extend_permission_allows_extend(): void
    {
        $admin = $this->createLimitedAdmin(['subscriptions.extend']);
        $target = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'W', 'owner_id' => $target->id]);
        $target->update(['workspace_id' => $ws->id]);

        $this->actingAs($admin)
            ->post(route('super_admin.extend', $target), ['days' => 30])
            ->assertRedirect();
    }

    public function test_users_view_without_extend_blocks_extend(): void
    {
        $admin = $this->createLimitedAdmin(['users.view']);
        $target = User::factory()->master()->create();

        $this->actingAs($admin)
            ->post(route('super_admin.extend', $target), ['days' => 30])
            ->assertStatus(403);
    }

    public function test_plans_update_permission_allows_update(): void
    {
        $admin = $this->createLimitedAdmin(['plans.update']);

        $this->actingAs($admin)
            ->put(route('super_admin.update_plan', $this->startPlan), [
                'max_appointments_per_month' => 50,
            ])
            ->assertRedirect();
    }

    public function test_plans_view_without_update_blocks_update(): void
    {
        $admin = $this->createLimitedAdmin(['plans.view']);

        $this->actingAs($admin)
            ->put(route('super_admin.update_plan', $this->startPlan), [
                'max_appointments_per_month' => 50,
            ])
            ->assertStatus(403);
    }

    public function test_impersonation_use_allows_start(): void
    {
        $admin = $this->createLimitedAdmin(['impersonation.use']);
        $target = User::factory()->master()->create();

        $this->actingAs($admin)
            ->post(route('super_admin.impersonate', $target))
            ->assertRedirect(route('admin.calendar'));
    }

    // ── E. ACTIVE / INACTIVE ──

    public function test_inactive_access_gets_403(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $user->id,
            'permissions' => ['dashboard.view', 'users.view'],
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('super_admin.dashboard'))
            ->assertStatus(403);

        $this->actingAs($user)
            ->get(route('super_admin.users'))
            ->assertStatus(403);
    }

    // ── F. PERMISSION LIST ──

    public function test_unknown_permission_denied(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        // A route with unknown permission should deny even ROOT... but there are no such routes.
        // Test the middleware directly by verifying enum validation.
        // For now, verify that a limited admin with a non-existent permission string is denied.
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $user->id,
            'permissions' => ['nonexistent.permission'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('super_admin.dashboard'))
            ->assertStatus(403);
    }

    public function test_unrelated_permission_denied(): void
    {
        $admin = $this->createLimitedAdmin(['audit.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.users'))
            ->assertStatus(403);
    }

    // ── G. IMPERSONATION EXIT REGRESSION ──

    public function test_limited_admin_can_start_and_leave_impersonation(): void
    {
        $admin = $this->createLimitedAdmin(['impersonation.use']);
        $target = User::factory()->master()->create();

        // Start impersonation
        $this->actingAs($admin)
            ->post(route('super_admin.impersonate', $target))
            ->assertRedirect(route('admin.calendar'));

        // Now authenticated as target — leave
        $this->post(route('super_admin.leave_impersonate'))
            ->assertRedirect(route('super_admin.dashboard'));

        $this->assertEquals($admin->id, auth()->id());
    }

    public function test_limited_admin_leave_clears_session(): void
    {
        $admin = $this->createLimitedAdmin(['impersonation.use']);
        $target = User::factory()->master()->create();

        $this->actingAs($admin)
            ->post(route('super_admin.impersonate', $target));

        $this->post(route('super_admin.leave_impersonate'));

        $this->assertNull(session('original_admin_id'));
    }
}
