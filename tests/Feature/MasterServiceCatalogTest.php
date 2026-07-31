<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspaceWithOwner(): User
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        return $owner;
    }

    private function createCatalog(Workspace $workspace): ServiceCatalog
    {
        return ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Маникюр',
            'category' => 'Ногтевой сервис',
            'base_price' => 1500.00,
            'base_duration' => 60,
            'is_active' => true,
        ]);
    }

    public function test_service_catalog_has_new_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('service_catalog', [
            'category',
            'base_price',
            'base_duration',
            'is_active',
        ]));
    }

    public function test_master_service_has_uuid_pk(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        $ms = MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertIsString($ms->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $ms->id
        );
    }

    public function test_master_service_links_to_catalog(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        $ms = MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertSame($catalog->id, $ms->catalog->id);
    }

    public function test_price_override_nullable(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        $ms = MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertNull($ms->price_override);
        $this->assertNull($ms->duration_override);
    }

    public function test_unique_master_catalog(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->expectException(QueryException::class);

        MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);
    }

    public function test_catalog_has_many_master_services(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $master2 = User::factory()->master()->create();
        MasterService::create([
            'master_id' => $master2->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertSame(2, $catalog->masterServices()->count());
    }

    public function test_status_defaults_approved(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        $ms = MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertSame('approved', $ms->status);
    }

    public function test_is_custom_defaults_false(): void
    {
        $owner = $this->createWorkspaceWithOwner();
        $catalog = $this->createCatalog($owner->workspace);

        $ms = MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->assertFalse($ms->is_custom);
    }
}
