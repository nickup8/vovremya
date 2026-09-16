<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\PlatformAdminAccess;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->master()->create([
            'is_super_admin' => true,
            'created_at' => now()->subDays(60),
        ]);
    }

    // ── Start impersonation ──

    public function test_super_admin_can_start_impersonation(): void
    {
        $target = User::factory()->master()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('super_admin.impersonate', $target));

        $response->assertRedirect(route('admin.calendar'));
        $this->assertEquals($target->id, auth()->id());
        $this->assertEquals($this->admin->id, session('original_admin_id'));
    }

    public function test_non_super_admin_cannot_start_impersonation(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        $target = User::factory()->master()->create();

        $this->actingAs($user)
            ->post(route('super_admin.impersonate', $target))
            ->assertStatus(403);
    }

    // ── Leave impersonation ──

    public function test_leave_restores_original_admin(): void
    {
        $target = User::factory()->master()->create();

        // Start impersonation
        $this->actingAs($this->admin)
            ->post(route('super_admin.impersonate', $target));

        // Now authenticated as target — leave
        $response = $this->post(route('super_admin.leave_impersonate'));

        $response->assertRedirect(route('super_admin.dashboard'));
        $this->assertEquals($this->admin->id, auth()->id());
    }

    public function test_leave_clears_session(): void
    {
        $target = User::factory()->master()->create();

        $this->actingAs($this->admin)
            ->post(route('super_admin.impersonate', $target));

        $this->post(route('super_admin.leave_impersonate'));

        $this->assertNull(session('original_admin_id'));
    }

    public function test_leave_creates_audit_row(): void
    {
        $target = User::factory()->master()->create();

        $this->actingAs($this->admin)
            ->post(route('super_admin.impersonate', $target));

        $this->post(route('super_admin.leave_impersonate'));

        $log = SuperAdminAuditLog::where('action', 'impersonation.ended')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->admin->id, $log->super_admin_id);
        $this->assertEquals($target->id, $log->metadata['impersonated_user_id']);
    }

    // ── Redirect by permission ──

    public function test_root_after_leave_redirects_to_dashboard(): void
    {
        $target = User::factory()->master()->create();

        $this->actingAs($this->admin)
            ->post(route('super_admin.impersonate', $target));

        $response = $this->post(route('super_admin.leave_impersonate'));

        $response->assertRedirect(route('super_admin.dashboard'));
    }

    public function test_limited_admin_without_dashboard_redirects_to_users(): void
    {
        $limitedAdmin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $limitedAdmin->id,
            'permissions' => ['users.view', 'impersonation.use'],
            'is_active' => true,
        ]);
        $target = User::factory()->master()->create();

        $this->actingAs($limitedAdmin)
            ->post(route('super_admin.impersonate', $target));

        $response = $this->post(route('super_admin.leave_impersonate'));

        $response->assertRedirect(route('super_admin.users'));
    }

    public function test_limited_admin_with_dashboard_redirects_to_dashboard(): void
    {
        $limitedAdmin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $limitedAdmin->id,
            'permissions' => ['dashboard.view', 'impersonation.use'],
            'is_active' => true,
        ]);
        $target = User::factory()->master()->create();

        $this->actingAs($limitedAdmin)
            ->post(route('super_admin.impersonate', $target));

        $response = $this->post(route('super_admin.leave_impersonate'));

        $response->assertRedirect(route('super_admin.dashboard'));
    }

    public function test_limited_admin_with_only_plans_redirects_to_plans(): void
    {
        $limitedAdmin = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $limitedAdmin->id,
            'permissions' => ['plans.view', 'impersonation.use'],
            'is_active' => true,
        ]);
        $target = User::factory()->master()->create();

        $this->actingAs($limitedAdmin)
            ->post(route('super_admin.impersonate', $target));

        $response = $this->post(route('super_admin.leave_impersonate'));

        $response->assertRedirect(route('super_admin.plans'));
    }

    // ── Security ──

    public function test_normal_user_without_session_gets_403(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->post(route('super_admin.leave_impersonate'))
            ->assertStatus(403);
    }

    public function test_nonexistent_original_admin_id_gets_403(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->withSession(['original_admin_id' => 'nonexistent-uuid'])
            ->post(route('super_admin.leave_impersonate'))
            ->assertStatus(403);
    }

    public function test_non_super_admin_original_id_gets_403(): void
    {
        $regularAdmin = User::factory()->master()->create(['is_super_admin' => false]);
        $target = User::factory()->master()->create();

        $this->actingAs($target)
            ->withSession(['original_admin_id' => $regularAdmin->id])
            ->post(route('super_admin.leave_impersonate'))
            ->assertStatus(403);
    }
}
