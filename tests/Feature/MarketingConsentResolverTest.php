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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingConsentResolverTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MarketingConsentResolver;
    }

    private function createClient(): Client
    {
        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $master->id]);

        return Client::factory()->create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
        ]);
    }

    private function grant(Client $client, array $overrides = []): ClientConsent
    {
        return ClientConsent::create(array_merge([
            'client_id' => $client->id,
            'workspace_id' => $client->workspace_id,
            'master_id' => $client->user_id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'version' => 'v1',
            'occurred_at' => now(),
        ], $overrides));
    }

    private function revoke(Client $client, array $overrides = []): ClientConsent
    {
        return ClientConsent::create(array_merge([
            'client_id' => $client->id,
            'workspace_id' => $client->workspace_id,
            'master_id' => $client->user_id,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Revoked,
            'occurred_at' => now(),
        ], $overrides));
    }

    // ── No marketing events ───────────────────────────────

    public function test_no_events_returns_absent(): void
    {
        $client = $this->createClient();

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Absent, $state->status);
        $this->assertNull($state->event);
    }

    // ── Single grant ──────────────────────────────────────

    public function test_single_grant_returns_granted(): void
    {
        $client = $this->createClient();
        $grant = $this->grant($client);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Granted, $state->status);
        $this->assertNotNull($state->event);
        $this->assertEquals($grant->id, $state->event->id);
    }

    // ── Grant then revoke ─────────────────────────────────

    public function test_grant_then_revoke_returns_revoked(): void
    {
        $client = $this->createClient();
        $this->grant($client, ['occurred_at' => now()->subDays(5)]);
        $revoke = $this->revoke($client, ['occurred_at' => now()]);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Revoked, $state->status);
        $this->assertEquals($revoke->id, $state->event->id);
    }

    // ── Grant → revoke → grant v2 ─────────────────────────

    public function test_grant_revoke_grant_v2_returns_granted(): void
    {
        $client = $this->createClient();
        $this->grant($client, ['version' => 'v1', 'occurred_at' => now()->subDays(10)]);
        $this->revoke($client, ['occurred_at' => now()->subDays(5)]);
        $grantV2 = $this->grant($client, ['version' => 'v2', 'occurred_at' => now()]);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Granted, $state->status);
        $this->assertEquals($grantV2->id, $state->event->id);
        $this->assertEquals('v2', $state->event->version);
    }

    // ── occurred_at wins over created_at ──────────────────

    public function test_occurred_at_wins_over_created_at(): void
    {
        $client = $this->createClient();

        // Insert a grant with later occurred_at but it will be created first in DB
        $grant = $this->grant($client, [
            'occurred_at' => now(),
        ]);

        // Insert a revoke with earlier occurred_at (simulates out-of-order insert)
        $this->revoke($client, [
            'occurred_at' => now()->subDays(3),
        ]);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Granted, $state->status);
        $this->assertEquals($grant->id, $state->event->id);
    }

    // ── Equal occurred_at — deterministic id DESC ─────────

    public function test_equal_occurred_at_uses_id_desc(): void
    {
        $client = $this->createClient();
        $sameTime = now();

        $grant = $this->grant($client, ['occurred_at' => $sameTime]);
        $revoke = $this->revoke($client, ['occurred_at' => $sameTime]);

        // UUIDv7: later insert → higher ID → revoke should win with id DESC
        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Revoked, $state->status);
        $this->assertEquals($revoke->id, $state->event->id);
    }

    // ── PDN is not marketing consent ──────────────────────

    public function test_pdn_consent_is_not_marketing(): void
    {
        $client = $this->createClient();
        $client->update([
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '1.0',
        ]);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Absent, $state->status);
        $this->assertNull($state->event);
    }

    // ── Client isolation ──────────────────────────────────

    public function test_other_client_isolation(): void
    {
        $clientA = $this->createClient();
        $clientB = $this->createClient();

        $this->grant($clientB);

        $stateA = $this->resolver->resolve($clientA);
        $stateB = $this->resolver->resolve($clientB);

        $this->assertSame(MarketingConsentStatus::Absent, $stateA->status);
        $this->assertSame(MarketingConsentStatus::Granted, $stateB->status);
    }

    // ── Source/channel/version preserved ──────────────────

    public function test_source_channel_version_preserved(): void
    {
        $client = $this->createClient();
        $this->grant($client, [
            'source' => 'telegram',
            'channel' => 'telegram',
            'version' => 'test-v1',
            'consent_text' => 'I agree to marketing.',
        ]);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Granted, $state->status);
        $this->assertEquals('telegram', $state->event->source);
        $this->assertEquals('telegram', $state->event->channel);
        $this->assertEquals('test-v1', $state->event->version);
        $this->assertEquals('I agree to marketing.', $state->event->consent_text);
    }

    // ── Nullable ownership snapshot ───────────────────────

    public function test_nullable_ownership_still_resolves(): void
    {
        $client = $this->createClient();

        ClientConsent::create([
            'client_id' => $client->id,
            'workspace_id' => null,
            'master_id' => null,
            'type' => ConsentType::Marketing,
            'action' => ConsentAction::Granted,
            'occurred_at' => now(),
        ]);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Granted, $state->status);
        $this->assertNotNull($state->event);
    }

    // ── Query count ───────────────────────────────────────

    public function test_single_query_per_resolve(): void
    {
        $client = $this->createClient();
        $this->grant($client, ['occurred_at' => now()->subDays(5)]);
        $this->grant($client, ['occurred_at' => now()->subDays(3)]);
        $this->revoke($client, ['occurred_at' => now()]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->resolver->resolve($client);

        $this->assertEquals(1, $queryCount);
    }
}
