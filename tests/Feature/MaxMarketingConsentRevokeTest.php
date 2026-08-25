<?php

namespace Tests\Feature;

use App\Enums\MarketingConsentStatus;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Consent\MarketingConsentResolver;
use App\Services\Consent\MarketingConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaxMarketingConsentRevokeTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentResolver $resolver;
    private MarketingConsentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MarketingConsentResolver;
        $this->service = new MarketingConsentService;
    }

    private function createMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        return [$master, $ws];
    }

    private function createClientWithMax(Workspace $ws, User $master, string $maxId = '67890'): Client
    {
        return Client::factory()->create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
            'max_id' => $maxId,
        ]);
    }

    // ── Absent → Revoked ──────────────────────────────────

    public function test_absent_becomes_revoked(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithMax($ws, $master);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: 'max:67890:mid123:client:' . $client->id,
            metadata: ['max_user_id' => '67890', 'max_mid' => 'mid123'],
        );

        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($client)->status);
    }

    // ── Granted → Revoked ─────────────────────────────────

    public function test_granted_becomes_revoked(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithMax($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'max', channel: 'max', occurredAt: now()->subDays(5),
            idempotencyKey: 'max:g:1',
        );

        $this->service->revoke(
            client: $client, master: $master,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: 'max:67890:mid123:client:' . $client->id,
        );

        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($client)->status);
    }

    // ── Duplicate same mid → no duplicate ─────────────────

    public function test_duplicate_mid_no_duplicate(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithMax($ws, $master);

        $key = 'max:67890:mid123:client:' . $client->id;

        $this->service->revoke(
            client: $client, master: $master,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: $key,
        );
        $this->service->revoke(
            client: $client, master: $master,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: $key,
        );

        $this->assertEquals(1, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Two Workspaces same max_id → both revoked ─────────

    public function test_two_workspaces_both_revoked(): void
    {
        [$masterA, $wsA] = $this->createMasterWithWorkspace();
        [$masterB, $wsB] = $this->createMasterWithWorkspace();

        $clientA = $this->createClientWithMax($wsA, $masterA, '88888');
        $clientB = $this->createClientWithMax($wsB, $masterB, '88888');

        $this->service->grant(
            client: $clientA, master: $masterA, version: 'v1', consentText: 'Text',
            source: 'max', channel: 'max', occurredAt: now()->subDays(3),
            idempotencyKey: 'max:g:a',
        );
        $this->service->grant(
            client: $clientB, master: $masterB, version: 'v1', consentText: 'Text',
            source: 'max', channel: 'max', occurredAt: now()->subDays(3),
            idempotencyKey: 'max:g:b',
        );

        $base = 'max:88888:mid456';
        $this->service->revoke(
            client: $clientA, master: $masterA,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: "{$base}:{$clientA->id}",
        );
        $this->service->revoke(
            client: $clientB, master: $masterB,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: "{$base}:{$clientB->id}",
        );

        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($clientA)->status);
        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($clientB)->status);
    }

    // ── Unknown Client → no rows ──────────────────────────

    public function test_unknown_client_no_rows(): void
    {
        $clients = Client::byMaxId('nonexistent')->get();
        $this->assertTrue($clients->isEmpty());
        $this->assertEquals(0, ClientConsent::count());
    }

    // ── Side effects unchanged ────────────────────────────

    public function test_side_effects_unchanged(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithMax($ws, $master);
        $client->update([
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '1.0',
            'disable_reactivation' => true,
            'is_blocked' => true,
        ]);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: 'max:67890:mid123:client:' . $client->id,
        );

        $client->refresh();
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertTrue($client->disable_reactivation);
        $this->assertTrue($client->is_blocked);
    }

    // ── Policy regression ─────────────────────────────────

    public function test_policy_can_send_becomes_false_after_stop(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithMax($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'max', channel: 'max', occurredAt: now()->subDays(5),
            idempotencyKey: 'max:g:1',
        );

        $policy = app(\App\Services\Consent\MarketingConsentPolicy::class);
        $this->assertTrue($policy->canSend($master, $client, 'test-v1'));

        $this->service->revoke(
            client: $client, master: $master,
            source: 'max', channel: 'max', occurredAt: now(),
            idempotencyKey: 'max:67890:mid123:client:' . $client->id,
        );

        $this->assertFalse($policy->canSend($master, $client, 'test-v1'));
    }
}
