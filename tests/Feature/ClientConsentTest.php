<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $workspace;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
        $this->workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->workspace->id]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
        ]);
    }

    public function test_grant_event_persists_all_fields(): void
    {
        $consent = ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => '1.0',
            'source' => 'telegram',
            'channel' => 'telegram',
            'consent_text' => 'I agree to receive marketing messages.',
            'metadata' => ['chat_id' => '12345'],
            'occurred_at' => now(),
        ]);

        $this->assertDatabaseHas('client_consents', [
            'id' => $consent->id,
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => 'marketing',
            'action' => 'granted',
            'version' => '1.0',
            'source' => 'telegram',
            'channel' => 'telegram',
            'consent_text' => 'I agree to receive marketing messages.',
        ]);

        $this->assertSame(['chat_id' => '12345'], $consent->fresh()->metadata);
        $this->assertInstanceOf(\DateTimeImmutable::class, $consent->fresh()->occurred_at);
    }

    public function test_revoke_creates_separate_row(): void
    {
        $grant = ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => '1.0',
            'consent_text' => 'I agree.',
            'occurred_at' => now()->subDay(),
        ]);

        $revoke = ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Revoked,
            'occurred_at' => now(),
        ]);

        $this->assertEquals(2, ClientConsent::count());
        $this->assertEquals(ConsentAction::Granted, $grant->fresh()->action);
        $this->assertEquals(ConsentAction::Revoked, $revoke->fresh()->action);
    }

    public function test_full_history_preserved_across_grant_revoke_grant(): void
    {
        ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => '1.0',
            'consent_text' => 'Text v1',
            'occurred_at' => now()->subDays(10),
        ]);

        ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Revoked,
            'occurred_at' => now()->subDays(5),
        ]);

        ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => '2.0',
            'consent_text' => 'Text v2',
            'occurred_at' => now(),
        ]);

        $history = $this->client->consents()
            ->where('type', ConsentType::Marketing)
            ->orderBy('occurred_at')
            ->get();

        $this->assertCount(3, $history);
        $this->assertEquals(ConsentAction::Granted, $history[0]->action);
        $this->assertEquals('1.0', $history[0]->version);
        $this->assertEquals(ConsentAction::Revoked, $history[1]->action);
        $this->assertNull($history[1]->version);
        $this->assertEquals(ConsentAction::Granted, $history[2]->action);
        $this->assertEquals('2.0', $history[2]->version);
    }

    public function test_workspace_isolation(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherWorkspace = Workspace::create(['name' => 'Other WS', 'owner_id' => $otherMaster->id]);
        $otherClient = Client::factory()->create([
            'user_id' => $otherMaster->id,
            'workspace_id' => $otherWorkspace->id,
        ]);

        ClientConsent::create([
            'client_id' => $this->client->id,
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => '1.0',
            'occurred_at' => now(),
        ]);

        ClientConsent::create([
            'client_id' => $otherClient->id,
            'workspace_id' => $otherWorkspace->id,
            'master_id' => $otherMaster->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => '1.0',
            'occurred_at' => now(),
        ]);

        $ws1Consents = ClientConsent::where('workspace_id', $this->workspace->id)->count();
        $ws2Consents = ClientConsent::where('workspace_id', $otherWorkspace->id)->count();

        $this->assertEquals(1, $ws1Consents);
        $this->assertEquals(1, $ws2Consents);

        $this->assertCount(1, $this->client->consents);
        $this->assertCount(1, $otherClient->consents);
    }
}
