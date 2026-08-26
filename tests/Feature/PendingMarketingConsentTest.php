<?php

namespace Tests\Feature;

use App\Enums\MarketingConsentStatus;
use App\Models\Client;
use App\Models\PendingMarketingConsent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Consent\MarketingConsentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingMarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function createPending(Client $client, Workspace $ws, array $overrides = []): PendingMarketingConsent
    {
        return PendingMarketingConsent::create(array_merge([
            'client_id' => $client->id,
            'workspace_id' => $ws->id,
            'legal_version' => 'test-v1',
            'rendered_consent_text' => 'You agree to receive marketing from Test Studio.',
            'source' => 'telegram',
            'channel' => 'telegram',
            'shown_at' => now(),
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    // ── Create ────────────────────────────────────────────

    public function test_create_pending_row(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $pending = $this->createPending($client, $ws);

        $this->assertDatabaseHas('pending_marketing_consents', [
            'id' => $pending->id,
            'client_id' => $client->id,
            'workspace_id' => $ws->id,
            'legal_version' => 'test-v1',
            'rendered_consent_text' => 'You agree to receive marketing from Test Studio.',
            'source' => 'telegram',
            'channel' => 'telegram',
            'consumed_at' => null,
        ]);

        $this->assertNotNull($pending->shown_at);
        $this->assertNotNull($pending->expires_at);
        $this->assertNull($pending->consumed_at);
    }

    // ── Casts ─────────────────────────────────────────────

    public function test_datetime_casts(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $pending = $this->createPending($client, $ws, [
            'shown_at' => '2026-01-15 10:30:00',
            'expires_at' => '2026-01-15 11:30:00',
            'consumed_at' => '2026-01-15 10:45:00',
        ]);

        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $pending->shown_at);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $pending->expires_at);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $pending->consumed_at);
    }

    // ── Relationships ─────────────────────────────────────

    public function test_client_relationship(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws);

        $this->assertInstanceOf(Client::class, $pending->client);
        $this->assertEquals($client->id, $pending->client->id);
    }

    public function test_workspace_relationship(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws);

        $this->assertInstanceOf(Workspace::class, $pending->workspace);
        $this->assertEquals($ws->id, $pending->workspace->id);
    }

    // ── Cascade delete ────────────────────────────────────

    public function test_cascade_on_client_delete(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws);

        $client->delete();

        $this->assertDatabaseMissing('pending_marketing_consents', [
            'id' => $pending->id,
        ]);
    }

    public function test_cascade_on_workspace_delete(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws);

        $ws->delete();

        $this->assertDatabaseMissing('pending_marketing_consents', [
            'id' => $pending->id,
        ]);
    }

    // ── Multiple pending rows ─────────────────────────────

    public function test_multiple_pending_for_same_client_workspace(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->createPending($client, $ws, ['shown_at' => now()->subHour()]);
        $this->createPending($client, $ws, ['shown_at' => now()]);

        $this->assertCount(2, PendingMarketingConsent::where('client_id', $client->id)->get());
    }

    // ── Expiry helper ─────────────────────────────────────

    public function test_is_expired_true_for_past(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws, [
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($pending->isExpired());
    }

    public function test_is_expired_false_for_future(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws, [
            'expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($pending->isExpired());
    }

    // ── Consumed helper ───────────────────────────────────

    public function test_is_consumed_false_when_null(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws);

        $this->assertFalse($pending->isConsumed());
    }

    public function test_is_consumed_true_when_set(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);
        $pending = $this->createPending($client, $ws, [
            'consumed_at' => now(),
        ]);

        $this->assertTrue($pending->isConsumed());
    }

    // ── Pending does NOT affect Resolver ──────────────────

    public function test_pending_does_not_affect_resolver_absent(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $this->createPending($client, $ws);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Absent, $state->status);
        $this->assertNull($state->event);
    }

    public function test_pending_does_not_affect_resolver_revoked(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        // Create a revoke consent event
        \App\Models\ClientConsent::create([
            'client_id' => $client->id,
            'workspace_id' => $ws->id,
            'master_id' => $master->id,
            'type' => \App\Enums\ConsentType::Marketing,
            'action' => \App\Enums\ConsentAction::Revoked,
            'occurred_at' => now(),
        ]);

        $this->createPending($client, $ws);

        $state = $this->resolver->resolve($client);

        $this->assertSame(MarketingConsentStatus::Revoked, $state->status);
    }

    // ── Cleanup command ───────────────────────────────────

    public function test_cleanup_deletes_expired_rows(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $expired = $this->createPending($client, $ws, [
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('pending-marketing:cleanup');

        $this->assertDatabaseMissing('pending_marketing_consents', [
            'id' => $expired->id,
        ]);
    }

    public function test_cleanup_preserves_future_rows(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $future = $this->createPending($client, $ws, [
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('pending-marketing:cleanup');

        $this->assertDatabaseHas('pending_marketing_consents', [
            'id' => $future->id,
        ]);
    }

    public function test_cleanup_preserves_consumed_not_expired(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $consumed = $this->createPending($client, $ws, [
            'consumed_at' => now()->subMinutes(30),
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('pending-marketing:cleanup');

        $this->assertDatabaseHas('pending_marketing_consents', [
            'id' => $consumed->id,
        ]);
    }

    public function test_cleanup_deletes_consumed_and_expired(): void
    {
        [$master, $ws] = $this->createWorkspaceWithMaster();
        $client = $this->createClient($ws, $master);

        $consumedExpired = $this->createPending($client, $ws, [
            'consumed_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('pending-marketing:cleanup');

        $this->assertDatabaseMissing('pending_marketing_consents', [
            'id' => $consumedExpired->id,
        ]);
    }

    public function test_cleanup_is_idempotent(): void
    {
        $this->artisan('pending-marketing:cleanup')->assertExitCode(0);
        $this->artisan('pending-marketing:cleanup')->assertExitCode(0);
    }
}
