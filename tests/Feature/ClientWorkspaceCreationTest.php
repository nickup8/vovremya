<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ClientPolicy;
use App\Services\Client\ClientMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientWorkspaceCreationTest extends TestCase
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

    public function test_client_controller_store_sets_workspace_id(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $response = $this->actingAs($master, 'web')
            ->postJson(route('admin.clients.store'), [
                'name' => 'Новый клиент',
                'phone' => '+79001112233',
            ]);

        $response->assertStatus(200);

        $client = Client::where('phone', '+79001112233')->first();
        $this->assertNotNull($client);
        $this->assertSame($workspace->id, $client->workspace_id);
    }

    public function test_client_merge_service_sets_workspace_id_on_create(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $service = app(ClientMergeService::class);
        $client = $service->findOrCreateByPhone(
            $master->id,
            '+79001112233',
            '',
            'Тест',
            $workspace->id,
        );

        $this->assertNotNull($client->id);
        $this->assertSame($workspace->id, $client->workspace_id);
    }

    public function test_client_merge_service_preserves_existing_client(): void
    {
        $workspace = $this->createWorkspace();
        $workspaceB = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $service = app(ClientMergeService::class);

        $client = $service->findOrCreateByPhone(
            $master->id,
            '+79001112233',
            '',
            'Первый',
            $workspace->id,
        );

        $this->assertSame($workspace->id, $client->workspace_id);

        $sameClient = $service->findOrCreateByPhone(
            $master->id,
            '+79001112233',
            '',
            'Второй',
            $workspaceB->id,
        );

        $this->assertSame($client->id, $sameClient->id, 'Must return same client (firstOrCreate)');
        $sameClient->refresh();
        $this->assertSame($workspace->id, $sameClient->workspace_id, 'Existing client must NOT be overwritten');
    }

    public function test_role_switch_sets_workspace_id(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $response = $this->actingAs($master, 'web')
            ->post(route('switch.to.client'));

        $response->assertRedirect();

        $client = Client::where('user_id', $master->id)->where('phone', $master->phone)->first();
        $this->assertNotNull($client);
        $this->assertSame($workspace->id, $client->workspace_id);
    }

    public function test_solo_master_creation_regression(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        $service = app(ClientMergeService::class);
        $client = $service->findOrCreateByPhone(
            $master->id,
            '+79001112233',
            '',
            'Solo клиент',
            $workspace->id,
        );

        $this->assertSame($workspace->id, $client->workspace_id);
        $this->assertTrue($client->is_personal);

        $policy = app(ClientPolicy::class);
        $this->assertTrue($policy->view($master, $client));
        $this->assertTrue($policy->update($master, $client));
    }

    public function test_null_workspace_master_creates_null_client(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $service = app(ClientMergeService::class);
        $client = $service->findOrCreateByPhone(
            $master->id,
            '+79001112233',
            '',
            'Legacy клиент',
            null,
        );

        $this->assertNull($client->workspace_id);
        $this->assertTrue($client->is_personal);
    }
}
