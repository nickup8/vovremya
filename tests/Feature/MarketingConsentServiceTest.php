<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Enums\MarketingConsentStatus;
use App\Exceptions\ConsentIdempotencyCollisionException;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Consent\MarketingConsentResolver;
use App\Services\Consent\MarketingConsentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingConsentServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentService $service;
    private MarketingConsentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketingConsentService;
        $this->resolver = new MarketingConsentResolver;
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

    // ── Grant snapshot ────────────────────────────────────

    public function test_grant_creates_correct_snapshot(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $occurredAt = new DateTimeImmutable('2026-08-20T10:00:00');

        $event = $this->service->grant(
            client: $client,
            master: $master,
            version: 'test-v1',
            consentText: 'I agree to marketing.',
            source: 'telegram',
            channel: 'telegram',
            occurredAt: $occurredAt,
            idempotencyKey: 'telegram:callback:123',
            metadata: ['external_id' => '123'],
        );

        $this->assertEquals($client->id, $event->client_id);
        $this->assertEquals($ws->id, $event->workspace_id);
        $this->assertEquals($master->id, $event->master_id);
        $this->assertSame(ConsentType::Marketing, $event->type);
        $this->assertSame(ConsentAction::Granted, $event->action);
        $this->assertEquals('test-v1', $event->version);
        $this->assertEquals('I agree to marketing.', $event->consent_text);
        $this->assertEquals('telegram', $event->source);
        $this->assertEquals('telegram', $event->channel);
        $this->assertEquals($occurredAt->getTimestamp(), $event->occurred_at->getTimestamp());
        $this->assertEquals('telegram:callback:123', $event->metadata['idempotency_key']);
        $this->assertEquals('123', $event->metadata['external_id']);
    }

    // ── Revoke snapshot ───────────────────────────────────

    public function test_revoke_creates_correct_snapshot(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $occurredAt = new DateTimeImmutable('2026-08-20T12:00:00');

        $event = $this->service->revoke(
            client: $client,
            master: $master,
            source: 'telegram',
            channel: 'telegram',
            occurredAt: $occurredAt,
            idempotencyKey: 'telegram:stop:456',
        );

        $this->assertSame(ConsentAction::Revoked, $event->action);
        $this->assertSame(ConsentType::Marketing, $event->type);
        $this->assertEquals('telegram', $event->source);
        $this->assertEquals('telegram', $event->channel);
        $this->assertNull($event->version);
        $this->assertNull($event->consent_text);
        $this->assertEquals('telegram:stop:456', $event->metadata['idempotency_key']);
    }

    // ── Grant duplicate ───────────────────────────────────

    public function test_grant_duplicate_returns_same_event(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $first = $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );

        $second = $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Revoke duplicate ──────────────────────────────────

    public function test_revoke_duplicate_returns_same_event(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $first = $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:stop:1',
        );

        $second = $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:stop:1',
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Grant/revoke/regrant ──────────────────────────────

    public function test_grant_revoke_regrant_creates_three_events(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text 1',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(10),
            idempotencyKey: 'tg:g1',
        );

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(5),
            idempotencyKey: 'tg:r1',
        );

        $this->service->grant(
            client: $client, master: $master, version: 'v2', consentText: 'Text 2',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:g2',
        );

        $this->assertEquals(3, ClientConsent::where('client_id', $client->id)->count());

        $state = $this->resolver->resolve($client);
        $this->assertSame(MarketingConsentStatus::Granted, $state->status);
        $this->assertEquals('v2', $state->event->version);
    }

    // ── Absent + revoke ───────────────────────────────────

    public function test_revoke_while_absent_creates_revoked_event(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:stop:1',
        );

        $state = $this->resolver->resolve($client);
        $this->assertSame(MarketingConsentStatus::Revoked, $state->status);
    }

    // ── New revoke after already revoked ──────────────────

    public function test_second_distinct_revoke_creates_two_events(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(3),
            idempotencyKey: 'tg:stop:a',
        );

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:stop:b',
        );

        $this->assertEquals(2, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Collision ─────────────────────────────────────────

    public function test_grant_then_revoke_same_key_throws(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:same',
        );

        $this->expectException(ConsentIdempotencyCollisionException::class);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:same',
        );
    }

    // ── Metadata merge ────────────────────────────────────

    public function test_metadata_merged_with_idempotency_key(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $event = $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
            metadata: ['external_id' => '123', 'locale' => 'ru'],
        );

        $this->assertEquals('tg:1', $event->metadata['idempotency_key']);
        $this->assertEquals('123', $event->metadata['external_id']);
        $this->assertEquals('ru', $event->metadata['locale']);
    }

    public function test_caller_cannot_override_idempotency_key(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $event = $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:canonical',
            metadata: ['idempotency_key' => 'evil'],
        );

        $this->assertEquals('tg:canonical', $event->metadata['idempotency_key']);
    }

    // ── occurred_at exactness ─────────────────────────────

    public function test_occurred_at_stored_exactly(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $frozen = new DateTimeImmutable('2026-06-15T08:30:00');

        $event = $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: $frozen,
            idempotencyKey: 'tg:1',
        );

        $this->assertEquals($frozen->getTimestamp(), $event->occurred_at->getTimestamp());
    }

    // ── PDN untouched ─────────────────────────────────────

    public function test_pdn_fields_unchanged(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $client->update(['pdn_consent_at' => now(), 'pdn_consent_version' => '1.0']);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );

        $client->refresh();
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertEquals('1.0', $client->pdn_consent_version);
    }

    // ── Reactivation opt-out untouched ────────────────────

    public function test_reactivation_opt_out_unchanged(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $client->update(['disable_reactivation' => true]);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );

        $client->refresh();
        $this->assertTrue($client->disable_reactivation);
    }

    // ── is_blocked untouched ──────────────────────────────

    public function test_is_blocked_unchanged(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $client->update(['is_blocked' => true]);

        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:stop:1',
        );

        $client->refresh();
        $this->assertTrue($client->is_blocked);
    }

    // ── Cross-workspace ───────────────────────────────────

    public function test_cross_workspace_master_throws(): void
    {
        [$masterA, $wsA] = $this->createWorkspaceWithMaster();
        [$masterB, $wsB] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($wsA, $masterA);

        $this->expectException(\DomainException::class);

        $this->service->grant(
            client: $client, master: $masterB, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );
    }

    public function test_cross_workspace_creates_no_rows(): void
    {
        [$masterA, $wsA] = $this->createWorkspaceWithMaster();
        [$masterB, $wsB] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($wsA, $masterA);

        try {
            $this->service->grant(
                client: $client, master: $masterB, version: 'v1', consentText: 'Text',
                source: 'telegram', channel: 'telegram', occurredAt: now(),
                idempotencyKey: 'tg:1',
            );
        } catch (\DomainException) {
            // expected
        }

        $this->assertEquals(0, ClientConsent::where('client_id', $client->id)->count());
    }

    // ── Resolver integration ──────────────────────────────

    public function test_resolver_reflects_service_writes(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        // Absent
        $this->assertSame(MarketingConsentStatus::Absent, $this->resolver->resolve($client)->status);

        // Granted
        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now()->subDays(5),
            idempotencyKey: 'g1',
        );
        $this->assertSame(MarketingConsentStatus::Granted, $this->resolver->resolve($client)->status);

        // Revoked
        $this->service->revoke(
            client: $client, master: $master,
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'r1',
        );
        $this->assertSame(MarketingConsentStatus::Revoked, $this->resolver->resolve($client)->status);
    }

    // ── Required field guards ─────────────────────────────

    public function test_grant_rejects_empty_version(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->grant(
            client: $client, master: $master, version: '', consentText: 'Text',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );
    }

    public function test_grant_rejects_empty_consent_text(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->grant(
            client: $client, master: $master, version: 'v1', consentText: '',
            source: 'telegram', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );
    }

    public function test_revoke_rejects_empty_source(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->revoke(
            client: $client, master: $master,
            source: '', channel: 'telegram', occurredAt: now(),
            idempotencyKey: 'tg:1',
        );
    }
}
