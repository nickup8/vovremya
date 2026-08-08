<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAssignTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_assign_service_to_master(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $master = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Маникюр',
            'base_price' => 1500,
            'base_duration' => 60,
        ]);

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.assign', [$catalog, $master]))
            ->assertRedirect();

        $this->assertDatabaseHas('master_service', [
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_detach_soft(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $master = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'web')
            ->delete(route('admin.catalog.detach', [$catalog, $master]))
            ->assertRedirect();

        $this->assertDatabaseHas('master_service', [
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => false,
        ]);
    }

    public function test_detach_preserves_price_override(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $master = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Педикюр',
            'base_price' => 2000,
            'base_duration' => 90,
        ]);

        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'price_override' => 999,
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'web')
            ->delete(route('admin.catalog.detach', [$catalog, $master]));

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.assign', [$catalog, $master]));

        $this->assertDatabaseHas('master_service', [
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
            'price_override' => 999,
        ]);
    }

    public function test_reassign_no_duplicate(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $master = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Услуга X',
            'base_price' => 500,
            'base_duration' => 30,
        ]);

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.assign', [$catalog, $master]));

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.assign', [$catalog, $master]));

        $this->assertDatabaseCount('master_service', 1);
    }

    public function test_cross_workspace_forbidden(): void
    {
        $ownerA = User::factory()->create(['is_master' => false]);
        $wsA = Workspace::create(['name' => 'WS-A', 'owner_id' => $ownerA->id]);
        $ownerA->update(['workspace_id' => $wsA->id, 'role' => UserRole::Owner]);

        $ownerB = User::factory()->create(['is_master' => false]);
        $wsB = Workspace::create(['name' => 'WS-B', 'owner_id' => $ownerB->id]);
        $ownerB->update(['workspace_id' => $wsB->id, 'role' => UserRole::Owner]);

        $masterB = User::factory()->master()->create(['workspace_id' => $wsB->id, 'role' => UserRole::Master]);

        $catalogA = ServiceCatalog::create([
            'workspace_id' => $wsA->id,
            'title' => 'Чужая услуга',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        $this->actingAs($ownerA, 'web')
            ->post(route('admin.catalog.assign', [$catalogA, $masterB]))
            ->assertStatus(403);

        $this->assertDatabaseMissing('master_service', [
            'master_id' => $masterB->id,
            'catalog_id' => $catalogA->id,
        ]);
    }

    public function test_non_manager_master_cannot_assign(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $masterA = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);
        $masterB = User::factory()->master()->create(['workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Услуга',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        $this->actingAs($masterA, 'web')
            ->post(route('admin.catalog.assign', [$catalog, $masterB]))
            ->assertStatus(403);
    }

    public function test_cannot_assign_non_master_user(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $notMaster = User::factory()->create(['is_master' => false, 'workspace_id' => $ws->id, 'role' => UserRole::Master]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Услуга',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.assign', [$catalog, $notMaster]))
            ->assertStatus(422);
    }
}
