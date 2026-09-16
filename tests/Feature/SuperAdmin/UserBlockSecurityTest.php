<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\PlatformAdminAccess;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBlockSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Self-block prevention ──

    public function test_root_cannot_block_self(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true, 'is_blocked' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.block', $root))
            ->assertStatus(403);

        $root->refresh();
        $this->assertFalse($root->is_blocked);
    }

    public function test_self_block_does_not_create_audit_row(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true, 'is_blocked' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.block', $root));

        $this->assertDatabaseCount('super_admin_audit_logs', 0);
    }

    public function test_limited_admin_cannot_block_self(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false, 'is_blocked' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => ['users.block', 'users.view'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('super_admin.block', $admin))
            ->assertStatus(403);

        $admin->refresh();
        $this->assertFalse($admin->is_blocked);
    }

    // ── Limited admin → ROOT ──

    public function test_limited_admin_cannot_block_root(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => ['users.block', 'users.view'],
            'is_active' => true,
        ]);

        $root = User::factory()->master()->create(['is_super_admin' => true, 'is_blocked' => false]);

        $this->actingAs($admin)
            ->post(route('super_admin.block', $root))
            ->assertStatus(403);

        $root->refresh();
        $this->assertFalse($root->is_blocked);
    }

    public function test_forbidden_block_does_not_create_audit(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => ['users.block', 'users.view'],
            'is_active' => true,
        ]);

        $root = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->post(route('super_admin.block', $root));

        $this->assertDatabaseCount('super_admin_audit_logs', 0);
    }

    // ── Allowed cases ──

    public function test_root_can_block_ordinary_user(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $user = User::factory()->master()->create(['is_blocked' => false]);

        $this->actingAs($root)
            ->post(route('super_admin.block', $user))
            ->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->is_blocked);
    }

    public function test_root_can_unblock_ordinary_user(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $user = User::factory()->master()->create(['is_blocked' => true]);

        $this->actingAs($root)
            ->post(route('super_admin.block', $user))
            ->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->is_blocked);
    }

    public function test_root_can_block_limited_admin(): void
    {
        $root = User::factory()->master()->create(['is_super_admin' => true]);
        $admin = User::factory()->master()->create(['is_super_admin' => false, 'is_blocked' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->actingAs($root)
            ->post(route('super_admin.block', $admin))
            ->assertRedirect();

        $admin->refresh();
        $this->assertTrue($admin->is_blocked);
    }

    public function test_limited_admin_can_block_ordinary_user(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $admin->id,
            'permissions' => ['users.block', 'users.view'],
            'is_active' => true,
        ]);

        $user = User::factory()->master()->create(['is_blocked' => false]);

        $this->actingAs($admin)
            ->post(route('super_admin.block', $user))
            ->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->is_blocked);
    }
}
