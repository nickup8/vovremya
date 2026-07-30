<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ClientPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientReadWorkspaceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_scope_returns_workspace_clients(): void
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

        $result = Client::forWorkspaceOrMaster($masterB)->pluck('id');

        $this->assertTrue($result->contains($client->id));
    }

    public function test_scope_excludes_other_workspace(): void
    {
        $workspaceX = $this->createWorkspace();
        $workspaceY = $this->createWorkspace();

        $masterX = $this->createMasterInWorkspace($workspaceX);

        $client = Client::create([
            'user_id' => $masterX->id,
            'workspace_id' => $workspaceY->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Чужой клиент',
        ]);

        $result = Client::forWorkspaceOrMaster($masterX)->pluck('id');

        $this->assertFalse($result->contains($client->id));
    }

    public function test_scope_legacy_null_fallback(): void
    {
        $masterA = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);
        $masterB = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $clientA = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79001112233',
            'name' => 'Клиент А',
        ]);

        $resultA = Client::forWorkspaceOrMaster($masterA)->pluck('id');
        $resultB = Client::forWorkspaceOrMaster($masterB)->pluck('id');

        $this->assertTrue($resultA->contains($clientA->id), 'Owner A must see own legacy client');
        $this->assertFalse($resultB->contains($clientA->id), 'Owner B must NOT see A legacy client');
    }

    public function test_scope_matches_policy(): void
    {
        $workspace = $this->createWorkspace();
        $masterA = $this->createMasterInWorkspace($workspace);
        $masterB = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => 'master',
        ]);

        $workspaceY = $this->createWorkspace();
        $masterY = $this->createMasterInWorkspace($workspaceY);

        $studioClient = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Студийный',
        ]);

        $foreignClient = Client::create([
            'user_id' => $masterY->id,
            'workspace_id' => $workspaceY->id,
            'is_personal' => false,
            'phone' => '+79009998877',
            'name' => 'Чужой',
        ]);

        $legacyClient = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79005554433',
            'name' => 'Legacy',
        ]);

        $policy = app(ClientPolicy::class);
        $scopeIds = Client::forWorkspaceOrMaster($masterB)->pluck('id');

        $this->assertTrue($scopeIds->contains($studioClient->id));
        $this->assertTrue($policy->view($masterB, $studioClient));

        $this->assertFalse($scopeIds->contains($foreignClient->id));
        $this->assertFalse($policy->view($masterB, $foreignClient));

        $this->assertFalse($scopeIds->contains($legacyClient->id));
        $this->assertFalse($policy->view($masterB, $legacyClient));

        $scopeIdsA = Client::forWorkspaceOrMaster($masterA)->pluck('id');
        $this->assertTrue($scopeIdsA->contains($legacyClient->id));
        $this->assertTrue($policy->view($masterA, $legacyClient));
    }

    public function test_client_index_shows_studio_clients(): void
    {
        $workspace = $this->createWorkspace();
        $masterA = $this->createMasterInWorkspace($workspace);
        $masterB = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => 'master',
        ]);

        Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Клиент A',
        ]);

        Client::create([
            'user_id' => $masterB->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79009998877',
            'name' => 'Клиент B',
        ]);

        $scopeIds = Client::forWorkspaceOrMaster($masterA)->pluck('id');
        $this->assertCount(2, $scopeIds);
        $this->assertCount(1, Client::where('name', 'Клиент B')->whereIn('id', $scopeIds)->get());
    }

    public function test_solo_master_read_regression(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $ownClient = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Свой',
        ]);

        $otherWorkspace = $this->createWorkspace();
        $otherMaster = $this->createMasterInWorkspace($otherWorkspace);

        $foreignClient = Client::create([
            'user_id' => $otherMaster->id,
            'workspace_id' => $otherWorkspace->id,
            'is_personal' => false,
            'phone' => '+79009998877',
            'name' => 'Чужой',
        ]);

        $scopeIds = Client::forWorkspaceOrMaster($master)->pluck('id');

        $this->assertTrue($scopeIds->contains($ownClient->id));
        $this->assertFalse($scopeIds->contains($foreignClient->id));
    }
}
