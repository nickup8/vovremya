<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Consent\MarketingConsentPolicy;
use App\Services\Consent\MarketingConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingConsentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentPolicy $policy;
    private MarketingConsentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(MarketingConsentPolicy::class);
        $this->service = new MarketingConsentService;
    }

    private function createWorkspaceWithMaster(): array
    {
        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        return [$master, $ws];
    }

    private function createClient(Workspace $ws, User $master): Client
    {
        return Client::factory()->create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
        ]);
    }

    // ── No events → false ─────────────────────────────────

    public function test_absent_returns_false(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->assertFalse($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Revoked → false ───────────────────────────────────

    public function test_revoked_returns_false(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(5),
            idempotencyKey: 'g1',
        );
        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'r1',
        );

        $this->assertFalse($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Granted matching version/workspace → true ─────────

    public function test_granted_matching_version_and_workspace_returns_true(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        $this->assertTrue($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Wrong version → false ─────────────────────────────

    public function test_wrong_version_returns_false(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        $this->assertFalse($this->policy->canSend($master, $client, 'test-v2'));
    }

    // ── Null version on event → false ─────────────────────

    public function test_null_event_version_returns_false(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        ClientConsent::create([
            'client_id' => $client->id,
            'workspace_id' => $ws->id,
            'master_id' => $master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => null,
            'source' => 'telegram',
            'channel' => 'telegram',
            'occurred_at' => now(),
        ]);

        $this->assertFalse($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Wrong event workspace → false ─────────────────────

    public function test_wrong_event_workspace_returns_false(): void
    {
        [$masterA, $wsA] = $this->createWorkspaceWithMaster();
        [$masterB, $wsB] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($wsA, $masterA);

        // Grant via masterA (workspace A)
        $this->service->grant(
            client: $client, master: $masterA, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        // Move client to workspace B
        $client->update(['workspace_id' => $wsB->id]);

        // Policy with masterB (workspace B) — event workspace is A, master workspace is B
        $this->assertFalse($this->policy->canSend($masterB, $client, 'test-v1'));
    }

    // ── Null event workspace → false ──────────────────────

    public function test_null_event_workspace_returns_false(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        ClientConsent::create([
            'client_id' => $client->id,
            'workspace_id' => null,
            'master_id' => $master->id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => 'test-v1',
            'source' => 'telegram',
            'channel' => 'telegram',
            'occurred_at' => now(),
        ]);

        $this->assertFalse($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Client moved workspace → false ────────────────────

    public function test_client_moved_workspace_returns_false(): void
    {
        [$masterA, $wsA] = $this->createWorkspaceWithMaster();
        [$masterB, $wsB] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($wsA, $masterA);

        $this->service->grant(
            client: $client, master: $masterA, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        // Move client to workspace B
        $client->update(['workspace_id' => $wsB->id]);

        // MasterB tries — client.workspace_id != masterB.workspace_id at policy level
        $this->assertFalse($this->policy->canSend($masterB, $client, 'test-v1'));
    }

    // ── Different master same workspace → true ────────────

    public function test_different_master_same_workspace_returns_true(): void
    {
        [$masterA, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $masterA);

        $this->service->grant(
            client: $client, master: $masterA, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        // MasterB in same workspace
        $masterB = User::factory()->master()->create(['workspace_id' => $ws->id]);

        $this->assertTrue($this->policy->canSend($masterB, $client, 'test-v1'));
    }

    // ── PDN does not help ─────────────────────────────────

    public function test_pdn_does_not_help(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $client->update(['pdn_consent_at' => now(), 'pdn_consent_version' => '1.0']);

        $this->assertFalse($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Channel does not gate yet ─────────────────────────

    public function test_channel_does_not_gate(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        // Policy doesn't take channel parameter — it's channel-agnostic
        $this->assertTrue($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Grant/revoke/regrant version semantics ────────────

    public function test_grant_revoke_regrant_version_semantics(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text 1',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(10),
            idempotencyKey: 'g1',
        );
        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(5),
            idempotencyKey: 'r1',
        );
        $this->service->grant(
            client: $client, master: $master, version: 'test-v2', consentText: 'Text 2',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g2',
        );

        // v2 matches → true
        $this->assertTrue($this->policy->canSend($master, $client, 'test-v2'));

        // v1 no longer matches → false
        $this->assertFalse($this->policy->canSend($master, $client, 'test-v1'));
    }

    // ── Query count ───────────────────────────────────────

    public function test_single_query_per_can_send(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'g1',
        );

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->policy->canSend($master, $client, 'test-v1');

        $this->assertEquals(1, $queryCount);
    }
}
