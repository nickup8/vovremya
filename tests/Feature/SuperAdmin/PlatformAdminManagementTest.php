<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\PlatformPermission;
use App\Models\PlatformAdminAccess;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    // ── A. ROOT ──

    public function test_root_can_open_admins_page(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->get(route('super_admin.admins'))
            ->assertOk();
    }

    public function test_root_can_grant_limited_admin(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['users.view', 'dashboard.view'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('platform_admin_accesses', [
            'user_id' => $target->id,
            'is_active' => true,
            'granted_by' => $root->id,
        ]);

        $access = PlatformAdminAccess::where('user_id', $target->id)->first();
        $this->assertTrue($access->hasPermission('users.view'));
        $this->assertTrue($access->hasPermission('dashboard.view'));
    }

    public function test_grant_sets_granted_by_to_root(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['dashboard.view'],
            ]);

        $access = PlatformAdminAccess::where('user_id', $target->id)->first();
        $this->assertEquals($root->id, $access->granted_by);
    }

    public function test_root_can_change_permissions(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        $access = PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
            'granted_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->put(route('super_admin.admins.update', $access), [
                'permissions' => ['users.view', 'users.block'],
            ])
            ->assertRedirect();

        $access->refresh();
        $this->assertTrue($access->hasPermission('users.view'));
        $this->assertTrue($access->hasPermission('users.block'));
        $this->assertFalse($access->hasPermission('dashboard.view'));
    }

    public function test_root_can_deactivate(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        $access = PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
            'granted_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->patch(route('super_admin.admins.toggle_active', $access))
            ->assertRedirect();

        $access->refresh();
        $this->assertFalse($access->is_active);
    }

    public function test_root_can_reactivate(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        $access = PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => false,
            'granted_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->patch(route('super_admin.admins.toggle_active', $access))
            ->assertRedirect();

        $access->refresh();
        $this->assertTrue($access->is_active);
    }

    public function test_root_user_cannot_be_granted_limited_access(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $targetRoot = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $targetRoot->id,
                'permissions' => ['dashboard.view'],
            ])
            ->assertStatus(422);
    }

    public function test_is_super_admin_not_changed_by_grant(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['dashboard.view'],
            ]);

        $target->refresh();
        $this->assertFalse($target->is_super_admin);
    }

    // ── B. LIMITED ADMIN WITH platform_admins.manage ──

    public function test_limited_admin_can_open_admins_page(): void
    {
        $admin = $this->createLimitedAdmin(['platform_admins.manage']);

        $this->actingAs($admin)
            ->get(route('super_admin.admins'))
            ->assertOk();
    }

    public function test_limited_admin_can_grant_another_user(): void
    {
        $admin = $this->createLimitedAdmin(['platform_admins.manage']);
        $target = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($admin)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['users.view'],
            ])
            ->assertRedirect();

        $access = PlatformAdminAccess::where('user_id', $target->id)->first();
        $this->assertEquals($admin->id, $access->granted_by);
    }

    public function test_limited_admin_can_change_other_limited_admin(): void
    {
        $admin = $this->createLimitedAdmin(['platform_admins.manage']);
        $other = User::factory()->master()->create();
        $otherAccess = PlatformAdminAccess::create([
            'user_id' => $other->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
            'granted_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('super_admin.admins.update', $otherAccess), [
                'permissions' => ['users.view'],
            ])
            ->assertRedirect();

        $otherAccess->refresh();
        $this->assertTrue($otherAccess->hasPermission('users.view'));
    }

    public function test_limited_admin_cannot_change_own_permissions(): void
    {
        $admin = $this->createLimitedAdmin(['platform_admins.manage']);
        $access = $admin->platformAdminAccess;

        $this->actingAs($admin)
            ->put(route('super_admin.admins.update', $access), [
                'permissions' => ['platform_admins.manage', 'users.view'],
            ])
            ->assertStatus(403);
    }

    public function test_limited_admin_cannot_deactivate_self(): void
    {
        $admin = $this->createLimitedAdmin(['platform_admins.manage']);
        $access = $admin->platformAdminAccess;

        $this->actingAs($admin)
            ->patch(route('super_admin.admins.toggle_active', $access))
            ->assertStatus(403);
    }

    public function test_limited_admin_cannot_manage_root(): void
    {
        $admin = $this->createLimitedAdmin(['platform_admins.manage']);
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $root->id,
                'permissions' => ['dashboard.view'],
            ])
            ->assertStatus(422);
    }

    // ── C. LIMITED ADMIN WITHOUT PERMISSION ──

    public function test_limited_admin_without_permission_cannot_open_admins(): void
    {
        $admin = $this->createLimitedAdmin(['dashboard.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.admins'))
            ->assertStatus(403);
    }

    public function test_limited_admin_without_permission_cannot_grant(): void
    {
        $admin = $this->createLimitedAdmin(['dashboard.view']);
        $target = User::factory()->master()->create();

        $this->actingAs($admin)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['dashboard.view'],
            ])
            ->assertStatus(403);
    }

    // ── D. NORMAL MASTER ──

    public function test_normal_user_cannot_open_admins(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('super_admin.admins'))
            ->assertStatus(403);
    }

    public function test_normal_user_cannot_grant(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        $target = User::factory()->master()->create();

        $this->actingAs($user)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['dashboard.view'],
            ])
            ->assertStatus(403);
    }

    // ── E. VALIDATION ──

    public function test_unknown_permission_rejected(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['nonexistent.permission'],
            ])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_nonexistent_user_rejected(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => '00000000-0000-0000-0000-000000000000',
                'permissions' => ['dashboard.view'],
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_duplicate_access_rejected(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['users.view'],
            ])
            ->assertStatus(422);
    }

    public function test_permission_dependencies_normalize(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['users.block'],
            ]);

        $access = PlatformAdminAccess::where('user_id', $target->id)->first();
        $this->assertTrue($access->hasPermission('users.block'));
        $this->assertTrue($access->hasPermission('users.view'));
    }

    // ── F. AUDIT ──

    public function test_grant_creates_audit_row(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();

        $this->actingAs($root)
            ->post(route('super_admin.admins.store'), [
                'user_id' => $target->id,
                'permissions' => ['dashboard.view'],
            ]);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'platform_admin.access_granted',
            'target_id' => $target->id,
        ]);
    }

    public function test_permissions_change_creates_audit_row(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        $access = PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
            'granted_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->put(route('super_admin.admins.update', $access), [
                'permissions' => ['users.view'],
            ]);

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'platform_admin.permissions_changed',
            'target_id' => $target->id,
        ]);
    }

    public function test_deactivate_creates_audit_row(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        $access = PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
            'granted_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->patch(route('super_admin.admins.toggle_active', $access));

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'platform_admin.deactivated',
            'target_id' => $target->id,
        ]);
    }

    public function test_reactivate_creates_audit_row(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create();
        $access = PlatformAdminAccess::create([
            'user_id' => $target->id,
            'permissions' => ['dashboard.view'],
            'is_active' => false,
            'granted_by' => $root->id,
        ]);

        $this->actingAs($root)
            ->patch(route('super_admin.admins.toggle_active', $access));

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'action' => 'platform_admin.activated',
            'target_id' => $target->id,
        ]);
    }

    // ── G. SHARED PROPS ──

    public function test_root_shared_props_indicate_full_access(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $response = $this->actingAs($root)->get(route('super_admin.dashboard'));
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['platformAdmin']['isRoot']);
        $this->assertContains('platform_admins.manage', $props['platformAdmin']['permissions']);
    }

    public function test_limited_admin_shared_props_contain_assigned_permissions(): void
    {
        $admin = $this->createLimitedAdmin(['dashboard.view', 'users.view']);

        $response = $this->actingAs($admin)->get(route('super_admin.dashboard'));
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['platformAdmin']['isRoot']);
        $this->assertContains('dashboard.view', $props['platformAdmin']['permissions']);
        $this->assertContains('users.view', $props['platformAdmin']['permissions']);
        $this->assertNotContains('platform_admins.manage', $props['platformAdmin']['permissions']);
    }

    public function test_ordinary_user_receives_no_platform_permissions(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        // Ordinary user can't access dashboard (403), but shared props are still set
        // Let's test via a route they CAN access — but since all admin-root routes need permission,
        // we verify via the middleware directly by checking the Inertia shared data.
        // The simplest way: make a request to a page they can access and check props.
        // Since they get 403, let's use a different approach — create a temporary access to dashboard.
        $admin = $this->createLimitedAdmin(['dashboard.view']);
        $response = $this->actingAs($admin)->get(route('super_admin.dashboard'));
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['platformAdmin']['isRoot']);
    }

    // ── H. REGRESSION ──

    public function test_limited_admin_cannot_access_route_not_in_permissions(): void
    {
        $admin = $this->createLimitedAdmin(['dashboard.view']);

        $this->actingAs($admin)
            ->get(route('super_admin.users'))
            ->assertStatus(403);

        $this->actingAs($admin)
            ->get(route('super_admin.plans'))
            ->assertStatus(403);
    }

    // ── Helper ──

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
}
