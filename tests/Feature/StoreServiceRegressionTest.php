<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreServiceRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithWorkspace(): User
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id]);

        return $master;
    }

    public function test_master_stores_service_via_http(): void
    {
        $master = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)
            ->post(route('admin.services.store'), [
                'title' => 'Стрижка',
                'price' => 1500,
                'duration_minutes' => 60,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(1, MasterService::count());
    }

    public function test_admin_stores_service_for_master(): void
    {
        $owner = $this->createMasterWithWorkspace();
        $master2 = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
        ]);

        $response = $this->actingAs($owner)
            ->post(route('admin.services.store'), [
                'title' => 'Маникюр',
                'price' => 2000,
                'duration_minutes' => 90,
                'master_id' => $master2->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $ms = MasterService::first();
        $this->assertSame($master2->id, $ms->master_id);

        $catalog = ServiceCatalog::first();
        $this->assertSame($owner->workspace_id, $catalog->workspace_id);
    }
}
