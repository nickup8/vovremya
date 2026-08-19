<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogDeleteRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createSoloWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id, 'role' => \App\Enums\UserRole::Owner]);

        return [$workspace, $master];
    }

    public function test_delete_catalog_with_ms_succeeds(): void
    {
        [$ws, $owner] = $this->createSoloWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Маникюр',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->delete("/admin/catalog/{$catalog->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('service_catalog', ['id' => $catalog->id]);
        $this->assertDatabaseMissing('master_service', ['catalog_id' => $catalog->id]);
    }

    public function test_delete_catalog_without_ms_succeeds(): void
    {
        [$ws, $owner] = $this->createSoloWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Маникюр',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->delete("/admin/catalog/{$catalog->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('service_catalog', ['id' => $catalog->id]);
    }

    public function test_delete_foreign_workspace_rejected(): void
    {
        [$wsA, $ownerA] = $this->createSoloWorkspace();

        $ownerB = User::factory()->master()->create();
        $wsB = Workspace::create([
            'name' => 'Studio B ' . Str::random(6),
            'owner_id' => $ownerB->id,
        ]);
        $ownerB->update(['workspace_id' => $wsB->id, 'role' => \App\Enums\UserRole::Owner]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $wsA->id,
            'title' => 'Маникюр',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $response = $this->actingAs($ownerB)->delete("/admin/catalog/{$catalog->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('service_catalog', ['id' => $catalog->id]);
    }
}
