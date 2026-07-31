<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\MasterServicePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterServicePolicyTest extends TestCase
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

    private function createMasterService(User $master, ServiceCatalog $catalog): MasterService
    {
        return MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
        ]);
    }

    public function test_owner_update_any_ms_own_ws(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);
        $master2 = User::factory()->master()->create(['workspace_id' => $ws->id]);
        $ms = $this->createMasterService($master2, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertTrue($policy->update($owner, $ms));
    }

    public function test_owner_cannot_update_ms_other_ws(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $ownerA->id]);
        $catalogB = $this->createCatalog($wsB);
        $masterB = User::factory()->master()->create(['workspace_id' => $wsB->id]);
        $msB = $this->createMasterService($masterB, $catalogB);

        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->update($ownerA, $msB));
    }

    public function test_master_update_own_ms(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $catalog = $this->createCatalog($ws);
        $ms = $this->createMasterService($master, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertTrue($policy->update($master, $ms));
    }

    public function test_master_cannot_update_other_ms(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $catalog = $this->createCatalog($ws);
        $other = User::factory()->master()->create(['workspace_id' => $ws->id]);
        $ms = $this->createMasterService($other, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->update($master, $ms));
    }

    public function test_delete_ws_scoped_for_owner(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $ownerA->id]);
        $catalogB = $this->createCatalog($wsB);
        $masterB = User::factory()->master()->create(['workspace_id' => $wsB->id]);
        $msB = $this->createMasterService($masterB, $catalogB);

        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->delete($ownerA, $msB));
    }

    public function test_master_delete_own(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $catalog = $this->createCatalog($ws);
        $ms = $this->createMasterService($master, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertTrue($policy->delete($master, $ms));
    }

    public function test_view_same_ws(): void
    {
        [$ws, $user] = $this->createWorkspaceWithRole(UserRole::Master);
        $catalog = $this->createCatalog($ws);
        $ms = $this->createMasterService($user, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertTrue($policy->view($user, $ms));
    }

    public function test_view_other_ws(): void
    {
        [$wsA, $userA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $userA->id]);
        $catalogB = $this->createCatalog($wsB);
        $masterB = User::factory()->master()->create(['workspace_id' => $wsB->id]);
        $msB = $this->createMasterService($masterB, $catalogB);

        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->view($userA, $msB));
    }

    public function test_owner_updateprice_always(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);
        $master2 = User::factory()->master()->create(['workspace_id' => $ws->id]);
        $ms = $this->createMasterService($master2, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertTrue($policy->updatePrice($owner, $ms));
    }

    public function test_master_updateprice_switch_ON(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $ws->setAllowMastersEditPrices(true);
        $catalog = $this->createCatalog($ws);
        $ms = $this->createMasterService($master, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertTrue($policy->updatePrice($master, $ms));
    }

    public function test_master_updateprice_switch_OFF(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        // свитч false по умолчанию
        $catalog = $this->createCatalog($ws);
        $ms = $this->createMasterService($master, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->updatePrice($master, $ms));
    }

    public function test_master_updateprice_own_only_switch_ON(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);
        $ws->setAllowMastersEditPrices(true);
        $catalog = $this->createCatalog($ws);
        $other = User::factory()->master()->create(['workspace_id' => $ws->id]);
        $ms = $this->createMasterService($other, $catalog);
        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->updatePrice($master, $ms));
    }

    public function test_owner_updateprice_other_ws_false(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);

        $wsB = Workspace::create(['name' => 'Studio B', 'owner_id' => $ownerA->id]);
        $catalogB = $this->createCatalog($wsB);
        $masterB = User::factory()->master()->create(['workspace_id' => $wsB->id]);
        $msB = $this->createMasterService($masterB, $catalogB);

        $policy = new MasterServicePolicy();

        $this->assertFalse($policy->updatePrice($ownerA, $msB));
    }
}
