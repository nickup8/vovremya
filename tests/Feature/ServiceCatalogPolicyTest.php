<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ServiceCatalogPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspaceWithRole(UserRole|string $role): array
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id, 'role' => UserRole::Owner]);

        $user = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return [$workspace, $user];
    }

    private function createCatalog(Workspace $workspace): ServiceCatalog
    {
        return ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1500,
            'base_duration' => 30,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_create_catalog(): void
    {
        [, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $policy = new ServiceCatalogPolicy();

        $this->assertTrue($policy->create($owner));
    }

    public function test_admin_can_create_catalog(): void
    {
        [, $admin] = $this->createWorkspaceWithRole(UserRole::Admin);
        $policy = new ServiceCatalogPolicy();

        $this->assertTrue($policy->create($admin));
    }

    public function test_master_cannot_create_catalog(): void
    {
        [, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $policy = new ServiceCatalogPolicy();

        $this->assertFalse($policy->create($master));
    }

    public function test_owner_update_own_ws_catalog(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);
        $policy = new ServiceCatalogPolicy();

        $this->assertTrue($policy->update($owner, $catalog));
    }

    public function test_owner_cannot_update_other_ws_catalog(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $ownerA->id]);
        $catalogB = $this->createCatalog($wsB);

        $policy = new ServiceCatalogPolicy();

        $this->assertFalse($policy->update($ownerA, $catalogB));
    }

    public function test_master_cannot_update_catalog(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $catalog = $this->createCatalog($ws);
        $policy = new ServiceCatalogPolicy();

        $this->assertFalse($policy->update($master, $catalog));
    }

    public function test_view_same_ws_true(): void
    {
        [$ws, $user] = $this->createWorkspaceWithRole(UserRole::Master);
        $catalog = $this->createCatalog($ws);
        $policy = new ServiceCatalogPolicy();

        $this->assertTrue($policy->view($user, $catalog));
    }

    public function test_view_other_ws_false(): void
    {
        [$wsA, $userA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $userA->id]);
        $catalogB = $this->createCatalog($wsB);

        $policy = new ServiceCatalogPolicy();

        $this->assertFalse($policy->view($userA, $catalogB));
    }

    public function test_delete_ws_scoped(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $ownerA->id]);
        $catalogB = $this->createCatalog($wsB);

        $policy = new ServiceCatalogPolicy();

        $this->assertFalse($policy->delete($ownerA, $catalogB));
    }
}
