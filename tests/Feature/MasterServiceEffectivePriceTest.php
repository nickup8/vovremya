<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterServiceEffectivePriceTest extends TestCase
{
    use RefreshDatabase;

    private function createCatalogWithDefaults(): ServiceCatalog
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id]);

        return ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 60,
            'is_active' => true,
        ]);
    }

    public function test_effective_price_uses_override(): void
    {
        $catalog = $this->createCatalogWithDefaults();
        $ms = MasterService::create([
            'master_id' => $catalog->workspace->owner_id,
            'catalog_id' => $catalog->id,
            'price_override' => 1500,
        ]);

        $this->assertSame('1500.00', $ms->effectivePrice);
    }

    public function test_effective_price_inherits_base(): void
    {
        $catalog = $this->createCatalogWithDefaults();
        $ms = MasterService::create([
            'master_id' => $catalog->workspace->owner_id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertSame('1000.00', $ms->effectivePrice);
    }

    public function test_effective_duration_uses_override(): void
    {
        $catalog = $this->createCatalogWithDefaults();
        $ms = MasterService::create([
            'master_id' => $catalog->workspace->owner_id,
            'catalog_id' => $catalog->id,
            'duration_override' => 90,
        ]);

        $this->assertSame(90, $ms->effectiveDuration);
    }

    public function test_effective_duration_inherits_base(): void
    {
        $catalog = $this->createCatalogWithDefaults();
        $ms = MasterService::create([
            'master_id' => $catalog->workspace->owner_id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertSame(60, $ms->effectiveDuration);
    }

    public function test_zero_override_not_inherited(): void
    {
        $catalog = $this->createCatalogWithDefaults();
        $ms = MasterService::create([
            'master_id' => $catalog->workspace->owner_id,
            'catalog_id' => $catalog->id,
            'price_override' => 0,
        ]);

        $this->assertSame('0.00', $ms->effectivePrice);
    }

    public function test_null_catalog_returns_null(): void
    {
        $catalog = $this->createCatalogWithDefaults();
        $ms = MasterService::create([
            'master_id' => $catalog->workspace->owner_id,
            'catalog_id' => $catalog->id,
        ]);

        // Эмулируем отсутствие catalog (catalog_id = null в БД)
        $ms->catalog_id = null;
        $ms->setRelation('catalog', null);

        $this->assertNull($ms->effectivePrice);
        $this->assertNull($ms->effectiveDuration);
    }
}
