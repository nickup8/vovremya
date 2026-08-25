<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
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

class TelegramMarketingConsentRevokeTest extends TestCase
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

    private function createClientWithTelegram(Workspace $ws, User $master, string $telegramId = '12345'): Client
    {
        return Client::factory()->create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
            'telegram_id' => $telegramId,
        ]);
    }

    // ── Absent → /stop → Revoked ─────────────────────────

    public function test_absent_becomes_revoked(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);

        $this->service->revoke(
            client: $client,
            master: $master,
            source: 'telegram',
            channel: 'telegram',
            occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
            metadata: ['tg_chat_id' => '12345', 'tg_update_id' => 100],
        );

        $state = $this->resolver->resolve($client);
        $this->assertSame(MarketingConsentStatus::Revoked, $state->status);
    }

    // ── Granted → append Revoked ──────────────────────────

    public function test_granted_becomes_revoked(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(5),
            idempotencyKey: 'tg:grant:1',
        );

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
            metadata: ['tg_chat_id' => '12345', 'tg_update_id' => 100],
        );

        $state = $this->resolver->resolve($client);
        $this->assertSame(MarketingConsentStatus::Revoked, $state->status);
    }

    // ── Duplicate same update_id → no duplicate ───────────

    public function test_duplicate_update_id_no_duplicate(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);

        $key = 'telegram:12345:100:client:' . $client->id;

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: $key,
        );

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: $key,
        );

        $this->assertEquals(1, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Two different /stop updates → two events ──────────

    public function test_two_different_updates_create_two_events(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(1),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
        );

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:101:client:' . $client->id,
        );

        $this->assertEquals(2, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Two Workspaces same telegram_id → both revoked ────

    public function test_two_workspaces_both_revoked(): void
    {
        [$masterA, $wsA] = $this->createMasterWithWorkspace();
        [$masterB, $wsB] = $this->createMasterWithWorkspace();

        $clientA = $this->createClientWithTelegram($wsA, $masterA, '99999');
        $clientB = $this->createClientWithTelegram($wsB, $masterB, '99999');

        $this->service->grant(
            client: $clientA, master: $masterA, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(3),
            idempotencyKey: 'tg:g:a',
        );
        $this->service->grant(
            client: $clientB, master: $masterB, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(3),
            idempotencyKey: 'tg:g:b',
        );

        // Simulate /stop for both
        $base = 'telegram:99999:200';
        $this->service->revoke(
            client: $clientA, master: $masterA,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: "{$base}:{$clientA->id}",
        );
        $this->service->revoke(
            client: $clientB, master: $masterB,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: "{$base}:{$clientB->id}",
        );

        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($clientA)->status);
        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($clientB)->status);
    }

    // ── Unknown Client → no rows ──────────────────────────

    public function test_unknown_client_no_rows(): void
    {
        $clients = Client::byTelegramId('nonexistent')->get();
        $this->assertTrue($clients->isEmpty());
        $this->assertEquals(0, ClientConsent::count());
    }

    // ── Start tariff works ────────────────────────────────

    public function test_start_tariff_works(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);

        // No subscription — still works (revoke is tariff-agnostic)
        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
        );

        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($client)->status);
    }

    // ── PDN unchanged ─────────────────────────────────────

    public function test_pdn_unchanged(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);
        $client->update(['pdn_consent_at' => now(), 'pdn_consent_version' => '1.0']);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
        );

        $client->refresh();
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertEquals('1.0', $client->pdn_consent_version);
    }

    // ── disable_reactivation unchanged ────────────────────

    public function test_disable_reactivation_unchanged(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);
        $client->update(['disable_reactivation' => true]);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
        );

        $client->refresh();
        $this->assertTrue($client->disable_reactivation);
    }

    // ── is_blocked unchanged ──────────────────────────────

    public function test_is_blocked_unchanged(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);
        $client->update(['is_blocked' => true]);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
        );

        $client->refresh();
        $this->assertTrue($client->is_blocked);
    }

    // ── Policy regression: canSend becomes false ──────────

    public function test_policy_can_send_becomes_false_after_stop(): void
    {
        [$master, $ws] = $this->createMasterWithWorkspace();
        $client = $this->createClientWithTelegram($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'test-v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(5),
            idempotencyKey: 'tg:g1',
        );

        $policy = app(\App\Services\Consent\MarketingConsentPolicy::class);
        $this->assertTrue($policy->canSend($master, $client, 'test-v1'));

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'telegram:12345:100:client:' . $client->id,
        );

        $this->assertFalse($policy->canSend($master, $client, 'test-v1'));
    }
}
