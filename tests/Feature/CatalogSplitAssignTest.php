<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSplitAssignTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_auto_assigns_to_the_only_master(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'Solo', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $this->actingAs($owner, 'web')->post(route('admin.catalog.store'), [
            'title' => 'Маникюр',
            'base_price' => 1500,
            'base_duration' => 60,
            'is_active' => true,
        ])->assertRedirect();

        $catalog = ServiceCatalog::where('workspace_id', $ws->id)->where('title', 'Маникюр')->first();
        $this->assertNotNull($catalog);

        $this->assertDatabaseHas('master_service', [
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    public function test_studio_one_master_assigns_to_master_not_owner(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'Studio', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $master = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $this->actingAs($owner, 'web')->post(route('admin.catalog.store'), [
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ])->assertRedirect();

        $catalog = ServiceCatalog::where('workspace_id', $ws->id)->where('title', 'Стрижка')->first();
        $this->assertNotNull($catalog);

        $this->assertDatabaseHas('master_service', ['master_id' => $master->id, 'catalog_id' => $catalog->id]);
        $this->assertDatabaseMissing('master_service', ['master_id' => $owner->id, 'catalog_id' => $catalog->id]);
    }

    public function test_studio_two_masters_rejects_create(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'Studio2', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);
        User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $this->actingAs($owner, 'web')->post(route('admin.catalog.store'), [
            'title' => 'Педикюр',
            'base_price' => 2000,
            'base_duration' => 90,
        ])->assertSessionHasErrors('title');

        $this->assertSame(0, ServiceCatalog::count());
    }

    public function test_zero_masters_rejects_create(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'Empty', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $this->actingAs($owner, 'web')->post(route('admin.catalog.store'), [
            'title' => 'Услуга X',
            'base_price' => 500,
            'base_duration' => 30,
        ])->assertSessionHasErrors('title');

        $this->assertSame(0, ServiceCatalog::count());
    }

    public function test_u6_master_cannot_view_catalog(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $master = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $this->actingAs($master, 'web')->get(route('admin.catalog.index'))->assertForbidden();
    }

    public function test_u6_owner_can_view_catalog(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS2', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $this->actingAs($owner, 'web')->get(route('admin.catalog.index'))->assertOk();
    }
}
