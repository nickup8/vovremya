<?php

namespace Tests\Unit;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_workspace_has_null_settings_and_default_accessors(): void
    {
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $this->assertNull($workspace->settings);
        $this->assertFalse($workspace->allow_masters_edit_prices);
    }

    public function test_set_allow_masters_edit_prices_persists(): void
    {
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $workspace->setAllowMastersEditPrices(true);

        $fresh = $workspace->fresh();

        $this->assertTrue($fresh->allow_masters_edit_prices);
        $this->assertTrue($fresh->settings['allow_masters_edit_prices']);
    }

    public function test_set_allow_masters_edit_prices_preserves_other_keys(): void
    {
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => User::factory()->create()->id,
            'settings' => ['foo' => 'bar'],
        ]);

        $workspace->setAllowMastersEditPrices(true);

        $fresh = $workspace->fresh();

        $this->assertTrue($fresh->allow_masters_edit_prices);
        $this->assertSame('bar', $fresh->settings['foo']);
    }

    public function test_solo_master_widget_still_works(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'solo-settings-test',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $workspace = Workspace::create(['name' => 'Solo WS', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertStatus(200);
    }
}
