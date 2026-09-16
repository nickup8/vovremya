<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditPageTest extends TestCase
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

    private function createLog(string $action, ?User $admin = null, ?string $createdAt = null): SuperAdminAuditLog
    {
        return SuperAdminAuditLog::create([
            'super_admin_id' => ($admin ?? $this->admin)->id,
            'action' => $action,
            'target_type' => null,
            'target_id' => null,
            'before' => null,
            'after' => null,
            'metadata' => null,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    // ── Access ──

    public function test_non_super_admin_gets_403(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('super_admin.audit'))
            ->assertStatus(403);
    }

    public function test_super_admin_can_access_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('super_admin.audit'))
            ->assertOk();
    }

    // ── Order ──

    public function test_logs_ordered_newest_first(): void
    {
        $old = $this->createLog('user.blocked', createdAt: now()->subDays(5));
        $new = $this->createLog('user.unblocked', createdAt: now()->subDays(1));

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.id', $new->id)
            ->where('logs.data.1.id', $old->id)
        );
    }

    // ── Pagination ──

    public function test_pagination_works(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->createLog('user.blocked', createdAt: now()->subDays($i));
        }

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.per_page', 50)
            ->where('logs.total', 55)
            ->where('logs.last_page', 2)
        );
    }

    // ── Filters ──

    public function test_action_filter(): void
    {
        $this->createLog('user.blocked');
        $this->createLog('user.unblocked');
        $this->createLog('plan.pro_price_updated');

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit', ['action' => 'user.blocked']));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 1)
            ->where('logs.data.0.action', 'user.blocked')
        );
    }

    public function test_super_admin_filter(): void
    {
        $otherAdmin = User::factory()->master()->create(['is_super_admin' => true]);
        $this->createLog('user.blocked', $this->admin);
        $this->createLog('user.blocked', $otherAdmin);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit', ['super_admin' => $otherAdmin->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 1)
            ->where('logs.data.0.super_admin_id', $otherAdmin->id)
        );
    }

    public function test_date_from_filter(): void
    {
        $this->createLog('user.blocked', createdAt: now()->subDays(10));
        $this->createLog('user.blocked', createdAt: now()->subDays(3));

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit', ['date_from' => now()->subDays(5)->toDateString()]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 1)
        );
    }

    public function test_date_to_filter(): void
    {
        $this->createLog('user.blocked', createdAt: now()->subDays(10));
        $this->createLog('user.blocked', createdAt: now()->subDays(3));

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit', ['date_to' => now()->subDays(5)->toDateString()]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 1)
        );
    }

    // ── Props ──

    public function test_filters_preserved_in_props(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit', [
            'action' => 'user.blocked',
            'date_from' => '2025-01-01',
        ]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('filters.action', 'user.blocked')
            ->where('filters.date_from', '2025-01-01')
            ->has('actions')
            ->has('admins')
        );
    }

    // ── Read-only ──

    public function test_audit_page_provides_no_mutation_routes(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.audit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 0)
        );

        // No POST/PUT/DELETE routes for audit
        $this->post('/admin-root/audit')->assertStatus(405);
        $this->put('/admin-root/audit')->assertStatus(405);
        $this->delete('/admin-root/audit')->assertStatus(405);
    }
}
