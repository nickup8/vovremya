<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogToggleActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_toggle_active(): void
    {
        $owner = User::factory()->create(['is_master' => false]);
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Маникюр',
            'base_price' => 1500,
            'base_duration' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.toggle-active', $catalog))
            ->assertRedirect();

        $this->assertDatabaseHas('service_catalog', ['id' => $catalog->id, 'is_active' => false]);

        $this->actingAs($owner, 'web')
            ->post(route('admin.catalog.toggle-active', $catalog))
            ->assertRedirect();

        $this->assertDatabaseHas('service_catalog', ['id' => $catalog->id, 'is_active' => true]);
    }

    public function test_master_cannot_toggle_active(): void
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
            'is_active' => true,
        ]);

        $this->actingAs($master, 'web')
            ->post(route('admin.catalog.toggle-active', $catalog))
            ->assertForbidden();

        $this->assertDatabaseHas('service_catalog', ['id' => $catalog->id, 'is_active' => true]);
    }

    public function test_toggle_respects_workspace(): void
    {
        $ownerA = User::factory()->create(['is_master' => false]);
        $wsA = Workspace::create(['name' => 'WS-A', 'owner_id' => $ownerA->id]);
        $ownerA->update(['workspace_id' => $wsA->id, 'role' => UserRole::Owner]);

        $ownerB = User::factory()->create(['is_master' => false]);
        $wsB = Workspace::create(['name' => 'WS-B', 'owner_id' => $ownerB->id]);
        $ownerB->update(['workspace_id' => $wsB->id, 'role' => UserRole::Owner]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $wsB->id,
            'title' => 'Педикюр',
            'base_price' => 2000,
            'base_duration' => 90,
            'is_active' => true,
        ]);

        $this->actingAs($ownerA, 'web')
            ->post(route('admin.catalog.toggle-active', $catalog))
            ->assertStatus(403);

        $this->assertDatabaseHas('service_catalog', ['id' => $catalog->id, 'is_active' => true]);
    }
}
