<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceCatalogTableTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspace(string $name = 'Test Studio'): Workspace
    {
        return Workspace::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    public function test_service_catalog_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('service_catalog'));
        $this->assertTrue(Schema::hasColumns('service_catalog', [
            'id',
            'workspace_id',
            'title',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_service_catalog_belongs_to_workspace(): void
    {
        $workspace = $this->createWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
        ]);

        $this->assertNotNull($catalog->id);
        $this->assertSame($workspace->id, $catalog->workspace->id);
    }

    public function test_service_catalog_unique_title_per_workspace(): void
    {
        $workspace = $this->createWorkspace();

        ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
        ]);
    }

    public function test_service_catalog_same_title_different_workspace_allowed(): void
    {
        $ws1 = $this->createWorkspace('Studio 1');
        $ws2 = $this->createWorkspace('Studio 2');

        $c1 = ServiceCatalog::create(['workspace_id' => $ws1->id, 'title' => 'Стрижка']);
        $c2 = ServiceCatalog::create(['workspace_id' => $ws2->id, 'title' => 'Стрижка']);

        $this->assertNotSame($c1->id, $c2->id);
        $this->assertSame(2, ServiceCatalog::count());
    }

    public function test_service_catalog_cascade_on_workspace_delete(): void
    {
        $workspace = $this->createWorkspace();

        ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Маникюр',
        ]);

        $this->assertSame(1, ServiceCatalog::count());

        $workspace->delete();

        $this->assertSame(0, ServiceCatalog::count());
    }
}
