<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SoloCatalogAtomicCreateTest extends TestCase
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

    public function test_creates_catalog_and_master_service_atomically(): void
    {
        [$ws, $owner] = $this->createSoloWorkspace();

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Маникюр',
            'category' => 'Ногти',
            'base_price' => 2000,
            'base_duration' => 60,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(1, MasterService::count());

        $catalog = ServiceCatalog::first();
        $ms = MasterService::first();

        $this->assertSame($owner->id, $ms->master_id);
        $this->assertSame($catalog->id, $ms->catalog_id);
        $this->assertTrue($ms->is_active);
        $this->assertNull($ms->price_override);
        $this->assertNull($ms->duration_override);
    }

    public function test_tenant_invariant_holds(): void
    {
        [$ws, $owner] = $this->createSoloWorkspace();

        $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Стрижка',
            'base_price' => 1500,
            'base_duration' => 30,
        ]);

        $catalog = ServiceCatalog::first();
        $ms = MasterService::first();

        $this->assertSame($catalog->workspace_id, $ms->master->workspace_id);
    }

    public function test_rejected_when_no_solo_master(): void
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Multi WS ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id, 'role' => \App\Enums\UserRole::Owner]);

        // Add a second master
        User::factory()->master()->create(['workspace_id' => $workspace->id]);

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Test',
            'base_price' => 100,
            'base_duration' => 30,
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame(0, ServiceCatalog::count());
        $this->assertSame(0, MasterService::count());
    }
}
