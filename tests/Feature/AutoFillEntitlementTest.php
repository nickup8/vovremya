<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoFillEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private TariffPlan $startPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);
    }

    private function createMasterWithPlan(TariffPlan $plan): User
    {
        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => $plan->price_monthly,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return $master;
    }

    // ── Feature entitlement ───────────────────────────────

    public function test_start_does_not_have_slot_autofill(): void
    {
        $master = $this->createMasterWithPlan($this->startPlan);

        $this->assertFalse($master->hasFeature('slot_autofill'));
    }

    public function test_pro_has_slot_autofill(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);

        $this->assertTrue($master->hasFeature('slot_autofill'));
    }

    // ── Default value ─────────────────────────────────────

    public function test_autofill_enabled_default_is_false(): void
    {
        $master = User::factory()->master()->create();

        $this->assertFalse($master->fresh()->autofill_enabled);
    }

    public function test_autofill_enabled_casts_to_boolean(): void
    {
        $master = User::factory()->master()->create();
        $master->update(['autofill_enabled' => true]);

        $this->assertIsBool($master->fresh()->autofill_enabled);
        $this->assertTrue($master->fresh()->autofill_enabled);
    }

    // ── Toggle behavior ───────────────────────────────────

    public function test_pro_can_enable_autofill(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);

        $this->actingAs($master)->put('/admin/settings/booking', [
            'autofill_enabled' => true,
            'slot_interval' => 30,
        ]);

        $this->assertTrue($master->fresh()->autofill_enabled);
    }

    public function test_pro_can_disable_autofill(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);
        $master->update(['autofill_enabled' => true]);

        $this->actingAs($master)->put('/admin/settings/booking', [
            'autofill_enabled' => false,
            'slot_interval' => 30,
        ]);

        $this->assertFalse($master->fresh()->autofill_enabled);
    }

    public function test_start_cannot_enable_autofill(): void
    {
        $master = $this->createMasterWithPlan($this->startPlan);

        $this->actingAs($master)->put('/admin/settings/booking', [
            'autofill_enabled' => true,
            'slot_interval' => 30,
        ]);

        $this->assertFalse($master->fresh()->autofill_enabled);
    }

    public function test_start_can_disable_autofill(): void
    {
        $master = $this->createMasterWithPlan($this->startPlan);
        $master->update(['autofill_enabled' => true]);

        $this->actingAs($master)->put('/admin/settings/booking', [
            'autofill_enabled' => false,
            'slot_interval' => 30,
        ]);

        $this->assertFalse($master->fresh()->autofill_enabled);
    }

    // ── Downgrade behavior ────────────────────────────────

    public function test_downgrade_does_not_change_stored_value(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);
        $master->update(['autofill_enabled' => true]);

        // Downgrade: expire Pro, add Start
        Subscription::where('workspace_id', $master->workspace_id)->update([
            'expires_at' => now()->subDay(),
        ]);

        Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        // Stored value unchanged
        $this->assertTrue($master->fresh()->autofill_enabled);
    }

    // ── Effective state ───────────────────────────────────

    public function test_pro_with_toggle_false_is_effectively_disabled(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);
        $master->update(['autofill_enabled' => false]);

        $this->assertFalse($master->isAutoFillEnabled());
    }

    public function test_start_with_toggle_true_is_effectively_disabled(): void
    {
        $master = $this->createMasterWithPlan($this->startPlan);
        $master->update(['autofill_enabled' => true]);

        $this->assertFalse($master->isAutoFillEnabled());
    }

    public function test_pro_with_toggle_true_is_effectively_enabled(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);
        $master->update(['autofill_enabled' => true]);

        $this->assertTrue($master->isAutoFillEnabled());
    }

    // ── Other settings preserved ──────────────────────────

    public function test_other_settings_continue_to_save(): void
    {
        $master = $this->createMasterWithPlan($this->proPlan);

        $this->actingAs($master)->put('/admin/settings', [
            'name' => 'Новое имя',
            'phone' => '+79991112233',
        ]);

        $this->actingAs($master)->put('/admin/settings/booking', [
            'autofill_enabled' => true,
            'slot_interval' => 30,
        ]);

        $master->refresh();
        $this->assertEquals('Новое имя', $master->name);
        $this->assertEquals('+79991112233', $master->phone);
        $this->assertTrue($master->autofill_enabled);
    }
}
