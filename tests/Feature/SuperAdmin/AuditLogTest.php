<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SuperAdminAuditLog;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $startPlan;
    private TariffPlan $proPlan;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 7,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        $this->admin = User::factory()->master()->create(['is_super_admin' => true]);
    }

    // ── Block / Unblock ──

    public function test_block_user_creates_audit_row(): void
    {
        $user = User::factory()->master()->create();

        $this->actingAs($this->admin)
            ->post(route('super_admin.block', $user));

        $this->assertDatabaseCount('super_admin_audit_logs', 1);

        $log = SuperAdminAuditLog::first();
        $this->assertEquals('user.blocked', $log->action);
        $this->assertEquals($this->admin->id, $log->super_admin_id);
        $this->assertEquals(User::class, $log->target_type);
        $this->assertEquals($user->id, $log->target_id);
        $this->assertEquals(['is_blocked' => false], $log->before);
        $this->assertEquals(['is_blocked' => true], $log->after);
    }

    public function test_unblock_user_creates_audit_row(): void
    {
        $user = User::factory()->master()->create(['is_blocked' => true]);

        $this->actingAs($this->admin)
            ->post(route('super_admin.block', $user));

        $log = SuperAdminAuditLog::first();
        $this->assertEquals('user.unblocked', $log->action);
        $this->assertEquals(['is_blocked' => true], $log->before);
        $this->assertEquals(['is_blocked' => false], $log->after);
    }

    // ── Subscription extend ──

    public function test_extend_subscription_creates_audit_row(): void
    {
        $user = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'WS', 'owner_id' => $user->id]);
        $sub = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);
        $user->update(['workspace_id' => $workspace->id]);

        $oldExpiry = $sub->fresh()->expires_at->toDateTimeString();

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $user), ['days' => 30]);

        $log = SuperAdminAuditLog::where('action', 'subscription.extended')->first();
        $this->assertNotNull($log);
        $this->assertEquals($sub->id, $log->target_id);
        $this->assertEquals($oldExpiry, $log->before['expires_at']);
        $this->assertEquals(30, $log->metadata['days_added']);
    }

    // ── Impersonation ──

    public function test_impersonation_start_creates_audit_row(): void
    {
        $user = User::factory()->master()->create();

        $this->actingAs($this->admin)
            ->post(route('super_admin.impersonate', $user));

        $log = SuperAdminAuditLog::where('action', 'impersonation.started')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->admin->id, $log->super_admin_id);
        $this->assertEquals($user->id, $log->target_id);
        $this->assertEquals($user->id, $log->metadata['target_user_id']);
    }

    // ── Plan changes ──

    public function test_start_limit_change_creates_audit_row(): void
    {
        $this->actingAs($this->admin)
            ->put(route('super_admin.update_plan', $this->startPlan), [
                'max_appointments_per_month' => 50,
            ]);

        $log = SuperAdminAuditLog::where('action', 'plan.start_limit_updated')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->startPlan->id, $log->target_id);
        $this->assertEquals(['max_appointments_per_month' => 7], $log->before);
        $this->assertEquals(['max_appointments_per_month' => 50], $log->after);
    }

    public function test_pro_price_change_creates_audit_row(): void
    {
        $this->actingAs($this->admin)
            ->put(route('super_admin.update_plan', $this->proPlan), [
                'price_monthly' => 690,
            ]);

        $log = SuperAdminAuditLog::where('action', 'plan.pro_price_updated')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->proPlan->id, $log->target_id);
        $this->assertEquals(['price_monthly' => 490], $log->before);
        $this->assertEquals(['price_monthly' => 690], $log->after);
    }

    // ── No audit on failure ──

    public function test_failed_validation_does_not_create_audit_row(): void
    {
        $this->actingAs($this->admin)
            ->put(route('super_admin.update_plan', $this->startPlan), [
                'max_appointments_per_month' => 0,
            ]);

        $this->assertDatabaseCount('super_admin_audit_logs', 0);
    }

    // ── Non-admin forbidden, no audit ──

    public function test_non_super_admin_action_creates_no_audit_row(): void
    {
        $regularUser = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($regularUser)
            ->put(route('super_admin.update_plan', $this->proPlan), [
                'price_monthly' => 999,
            ]);

        $this->assertDatabaseCount('super_admin_audit_logs', 0);
    }

    // ── Sensitive data not stored ──

    public function test_sensitive_request_data_not_stored_in_audit(): void
    {
        $user = User::factory()->master()->create();

        $this->actingAs($this->admin)
            ->post(route('super_admin.block', $user), [
                'password' => 'secret123',
                'token' => 'api-token-xyz',
                'email' => 'hacker@evil.com',
            ]);

        $log = SuperAdminAuditLog::first();
        $this->assertNotNull($log);
        $this->assertNull($log->metadata);

        $json = json_encode($log->toArray());
        $this->assertStringNotContainsString('secret123', $json);
        $this->assertStringNotContainsString('api-token-xyz', $json);
    }
}
