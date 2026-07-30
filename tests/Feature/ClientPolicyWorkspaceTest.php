<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ClientPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPolicyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private ClientPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ClientPolicy();
    }

    private static int $wsCounter = 0;

    private function createWorkspace(): Workspace
    {
        self::$wsCounter++;

        return Workspace::create([
            'name' => 'Studio '.self::$wsCounter,
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function createMasterInWorkspace(Workspace $workspace): User
    {
        return User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => 'owner',
        ]);
    }

    public function test_master_can_access_own_workspace_client(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $client = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Иван',
        ]);

        $this->assertTrue($this->policy->view($master, $client));
        $this->assertTrue($this->policy->update($master, $client));
        $this->assertTrue($this->policy->delete($master, $client));
    }

    public function test_master_cannot_access_other_workspace_client(): void
    {
        $workspaceA = $this->createWorkspace();
        $workspaceB = $this->createWorkspace();

        $masterA = $this->createMasterInWorkspace($workspaceA);

        $client = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => $workspaceB->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Чужой',
        ]);

        $this->assertFalse($this->policy->view($masterA, $client));
        $this->assertFalse($this->policy->update($masterA, $client));
        $this->assertFalse($this->policy->delete($masterA, $client));
    }

    public function test_second_master_same_workspace_can_access(): void
    {
        $workspace = $this->createWorkspace();

        $masterA = $this->createMasterInWorkspace($workspace);
        $masterB = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => 'master',
        ]);

        $client = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Общий клиент',
        ]);

        $this->assertTrue($this->policy->view($masterB, $client));
        $this->assertTrue($this->policy->update($masterB, $client));
        $this->assertTrue($this->policy->delete($masterB, $client));
    }

    public function test_null_workspace_client_accessible_by_owner(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $client = Client::create([
            'user_id' => $master->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79001112233',
            'name' => 'Solo клиент',
        ]);

        $this->assertTrue($this->policy->view($master, $client));
        $this->assertTrue($this->policy->update($master, $client));
        $this->assertTrue($this->policy->delete($master, $client));
    }

    public function test_null_workspace_client_denied_for_non_owner(): void
    {
        $masterA = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $masterB = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $client = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79001112233',
            'name' => 'Клиент А',
        ]);

        $this->assertFalse($this->policy->view($masterB, $client));
        $this->assertFalse($this->policy->update($masterB, $client));
        $this->assertFalse($this->policy->delete($masterB, $client));
    }

    public function test_solo_master_regression(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $ownClient = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Свой клиент',
        ]);

        $otherWorkspace = $this->createWorkspace();
        $otherMaster = $this->createMasterInWorkspace($otherWorkspace);

        $foreignClient = Client::create([
            'user_id' => $otherMaster->id,
            'workspace_id' => $otherWorkspace->id,
            'is_personal' => false,
            'phone' => '+79009998877',
            'name' => 'Чужой клиент',
        ]);

        $this->assertTrue($this->policy->view($master, $ownClient));
        $this->assertFalse($this->policy->view($master, $foreignClient));

        $this->assertTrue($this->policy->update($master, $ownClient));
        $this->assertFalse($this->policy->update($master, $foreignClient));
    }
}
