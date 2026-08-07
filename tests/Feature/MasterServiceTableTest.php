<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterServiceTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_service_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('master_service'));
    }

    public function test_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('master_service', [
            'id',
            'master_id',
            'catalog_id',
            'price_override',
            'duration_override',
            'is_active',
        ]));
    }

    public function test_table_is_empty(): void
    {
        $this->assertSame(0, MasterService::count());
    }

    public function test_can_create_link_with_override(): void
    {
        $master = User::factory()->master()->create();
        $catalog = ServiceCatalog::factory()->create();

        // Confirm ID is UUID (matching production schema)
        $this->assertIsString($master->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $master->id);

        $link = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'price_override' => 1500.00,
            'duration_override' => 60,
            'is_active' => true,
        ]);

        $this->assertNotNull($link->id);
        $this->assertSame('1500.00', $link->price_override);
        $this->assertTrue($link->is_active);
        $this->assertSame(60, $link->duration_override);
    }

    public function test_nullable_override_defaults(): void
    {
        $master = User::factory()->master()->create();
        $catalog = ServiceCatalog::factory()->create();

        $link = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $this->assertNull($link->price_override);
        $this->assertNull($link->duration_override);
        $this->assertTrue($link->is_active);
    }

    public function test_unique_master_service(): void
    {
        $master = User::factory()->master()->create();

        $catalog = \App\Models\ServiceCatalog::factory()->create();

        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
        ]);
    }
}
