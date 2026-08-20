<?php

namespace Tests\Feature\Channels;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAnalyticsAccessTest extends TestCase
{
    use MakesTariffMasters, RefreshDatabase;

    // ─── PROFI sees real channel data ───

    public function test_pro_master_gets_channel_feature_and_top_channels(): void
    {
        $master = $this->proMaster();

        $this->actingAs($master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('channels_feature', true)
                ->has('top_channels'));
    }

    public function test_pro_master_channels_tab_returns_links_and_channels(): void
    {
        $master = $this->proMaster();
        TrackingLink::factory()->forMaster($master)->create(['name' => 'Instagram']);

        $this->actingAs($master)
            ->get(route('admin.analytics', ['period' => 'month', 'tab' => 'channels']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('channels_feature', true)
                ->where('activeTab', 'channels')
                ->has('tracking_links', 1)
                ->has('channels'));
    }

    // ─── START sees locked state, no real data ───

    public function test_start_master_sees_locked_feature_flag_and_no_real_data(): void
    {
        $master = $this->startMaster();

        $this->actingAs($master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('channels_feature', false)
                ->where('top_channels', null));
    }

    public function test_start_master_channels_tab_gets_no_links_or_channels(): void
    {
        $master = $this->startMaster();
        // Даже если в БД есть ссылки (например, историк после downgrade) — START их не получает.
        TrackingLink::factory()->forMaster($master)->create(['name' => 'Instagram']);

        $this->actingAs($master)
            ->get(route('admin.analytics', ['period' => 'month', 'tab' => 'channels']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('channels_feature', false)
                ->where('channels', null)
                ->where('tracking_links', null));
    }

    // ─── Downgrade PROFI → START ───

    public function test_downgrade_closes_management_and_analytics_but_keeps_data(): void
    {
        $master = $this->proMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['name' => 'Instagram', 'is_active' => true]);

        $this->downgradeToStart($master);
        $master = $master->fresh();

        // CRUD закрыт.
        $this->actingAs($master)
            ->post(route('admin.tracking-links.store'), ['name' => 'New'])
            ->assertForbidden();

        // Analytics endpoint отдаёт locked (без реальных данных).
        $this->actingAs($master)
            ->get(route('admin.analytics', ['period' => 'month', 'tab' => 'channels']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('channels_feature', false));

        // Данные (ссылка) не удалены.
        $this->assertDatabaseHas('tracking_links', ['id' => $link->id, 'is_active' => true]);
    }

    // ─── Upgrade back START → PROFI restores access ───

    public function test_upgrade_back_restores_access_to_accumulated_data(): void
    {
        $master = $this->proMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['name' => 'Instagram']);
        $this->downgradeToStart($master);

        // Возврат на ПРОФИ = новая активная подписка.
        $master = $master->fresh();
        Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan()->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($master->fresh())
            ->get(route('admin.analytics', ['period' => 'month', 'tab' => 'channels']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('channels_feature', true)
                ->has('tracking_links', 1));
    }
}
