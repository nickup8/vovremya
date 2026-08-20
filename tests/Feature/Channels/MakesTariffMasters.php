<?php

namespace Tests\Feature\Channels;

use App\Enums\SubscriptionStatus;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;

/**
 * Хелперы создания мастеров на тарифах ПРОФИ / START для channel-тестов.
 */
trait MakesTariffMasters
{
    protected function proPlan(): TariffPlan
    {
        return TariffPlan::firstOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Профи', 'price_monthly' => 490,
                'max_appointments_per_month' => null, 'max_masters' => 1,
                'features' => ['unlimited_appointments', 'client_management', 'channel_analytics'],
                'is_active' => true,
            ],
        );
    }

    protected function proMaster(): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);
        $workspace = Workspace::create(['name' => 'WS '.uniqid(), 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $workspace->id]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan()->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return $master->fresh();
    }

    /**
     * START = workspace без активной подписки с channel_analytics.
     */
    protected function startMaster(): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);
        $workspace = Workspace::create(['name' => 'WS '.uniqid(), 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $workspace->id]);

        return $master->fresh();
    }

    /**
     * Понижает мастера ПРОФИ → START (истёкшая подписка), не трогая ссылки.
     */
    protected function downgradeToStart(User $master): void
    {
        Subscription::where('workspace_id', $master->workspace_id)
            ->update([
                'status' => SubscriptionStatus::Expired->value,
                'expires_at' => now()->subDay(),
            ]);
    }

    protected function makeMasterService(User $master): MasterService
    {
        $catalog = ServiceCatalog::create([
            'workspace_id' => $master->workspace_id,
            'title' => 'Услуга',
            'base_price' => 1000,
            'base_duration' => 60,
            'is_active' => true,
        ]);

        return MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }
}
