<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceUpdateBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private function createSoloWithService(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Маникюр',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $ms = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        return [$master, $workspace, $catalog, $ms];
    }

    public function test_old_appointment_keeps_old_price_after_catalog_update(): void
    {
        [$master, $ws, $catalog, $ms] = $this->createSoloWithService();

        $apptA = Appointment::factory()->forMaster($master)->create([
            'master_service_id' => $ms->id,
            'service_name' => 'Маникюр',
            'price' => 1000,
            'duration' => 30,
        ]);

        // Update catalog price
        $catalog->update(['base_price' => 2000]);

        // Old appointment snapshot unchanged
        $apptA->refresh();
        $this->assertSame(1000.0, (float) $apptA->price);
    }

    public function test_new_appointment_uses_new_price(): void
    {
        [$master, $ws, $catalog, $ms] = $this->createSoloWithService();

        $apptA = Appointment::factory()->forMaster($master)->create([
            'master_service_id' => $ms->id,
            'service_name' => 'Маникюр',
            'price' => 1000,
            'duration' => 30,
        ]);

        // Update catalog price
        $catalog->update(['base_price' => 2000]);

        // effective_price should now return new price
        $ms->refresh();
        $this->assertSame(2000.0, (float) $ms->effective_price);

        // Old snapshot unchanged
        $apptA->refresh();
        $this->assertSame(1000.0, (float) $apptA->price);
    }
}
