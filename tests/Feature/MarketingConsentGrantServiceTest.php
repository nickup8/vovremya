<?php

namespace Tests\Feature;

use App\Enums\AppointmentSource;
use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Enums\MarketingConsentGrantResult;
use App\Enums\SubscriptionStatus;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\PendingMarketingConsent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Consent\MarketingConsentGrantService;
use App\Services\Consent\MarketingConsentResolver;
use App\Services\Consent\MarketingConsentService;
use App\Services\Consent\MarketingConsentContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingConsentGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentGrantService $grantService;
    private MarketingConsentResolver $resolver;
    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'client_reactivation'],
            'is_active' => true,
        ]);

        $this->grantService = app(MarketingConsentGrantService::class);
        $this->resolver = new MarketingConsentResolver;
    }

    private function createProWorkspace(): array
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return [$owner, $ws];
    }

    private function createTelegramClient(Workspace $ws, User $owner, string $telegramId): Client
    {
        return Client::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $ws->id,
            'telegram_id' => $telegramId,
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

    // ── Single Client ─────────────────────────────────────

    public function test_single_client_granted(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_123');
        $pending = $this->createPending($client, $ws);
        $occurredAt = Carbon::parse('2026-09-01 12:00:00');

        $result = $this->grantService->grantPending(
            $pending->id,
            AppointmentSource::Telegram,
            'tg_123',
            $occurredAt,
        );

        $this->assertSame(MarketingConsentGrantResult::Granted, $result);

        $this->assertDatabaseHas('client_consents', [
            'client_id' => $client->id,
            'workspace_id' => $ws->id,
            'master_id' => $owner->id,
            'type' => ConsentType::Marketing->value,
            'action' => ConsentAction::Granted->value,
            'version' => 'test-v1',
            'consent_text' => 'You agree to receive marketing from Test Studio.',
            'source' => 'telegram',
            'channel' => 'telegram',
            'occurred_at' => '2026-09-01 12:00:00',
        ]);

        $pending->refresh();
        $this->assertNotNull($pending->consumed_at);
        $this->assertEquals($occurredAt, $pending->consumed_at);

        $state = $this->resolver->resolve($client);
        $this->assertSame(\App\Enums\MarketingConsentStatus::Granted, $state->status);
    }

    // ── Fan-out 3 Clients ─────────────────────────────────

    public function test_fan_out_three_clients(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $telegramId = 'tg_fanout';

        $clientA = $this->createTelegramClient($ws, $owner, $telegramId);
        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);
        $clientC = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);

        $pending = $this->createPending($clientA, $ws);
        $occurredAt = now();

        $result = $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, $telegramId, $occurredAt,
        );

        $this->assertSame(MarketingConsentGrantResult::Granted, $result);

        $consents = ClientConsent::where('type', ConsentType::Marketing)
            ->where('action', ConsentAction::Granted)
            ->where('metadata->pending_marketing_consent_id', $pending->id)
            ->get();

        $this->assertCount(3, $consents);

        foreach ($consents as $consent) {
            $this->assertEquals('test-v1', $consent->version);
            $this->assertEquals('You agree to receive marketing from Test Studio.', $consent->consent_text);
            $this->assertEquals($occurredAt->format('Y-m-d H:i:s'), $consent->occurred_at->format('Y-m-d H:i:s'));
            $this->assertEquals($ws->id, $consent->workspace_id);
        }
    }

    // ── Mixed Granted + Absent ────────────────────────────

    public function test_mixed_granted_and_absent(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $telegramId = 'tg_mixed';

        $clientA = $this->createTelegramClient($ws, $owner, $telegramId);
        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);

        // Pre-grant A
        app(MarketingConsentService::class)->grant(
            client: $clientA, master: $owner, version: 'old-v1',
            consentText: 'Old text.', source: 'telegram', channel: 'telegram',
            occurredAt: now()->subDay(), idempotencyKey: 'old-key-a',
        );

        $pending = $this->createPending($clientA, $ws);
        $occurredAt = now();

        $result = $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, $telegramId, $occurredAt,
        );

        $this->assertSame(MarketingConsentGrantResult::Granted, $result);

        // Both should have new Granted
        $newConsents = ClientConsent::where('metadata->pending_marketing_consent_id', $pending->id)->get();
        $this->assertCount(2, $newConsents);

        $stateA = $this->resolver->resolve($clientA);
        $stateB = $this->resolver->resolve($clientB);
        $this->assertSame(\App\Enums\MarketingConsentStatus::Granted, $stateA->status);
        $this->assertSame(\App\Enums\MarketingConsentStatus::Granted, $stateB->status);
    }

    // ── Granted + Revoked both get new Granted ────────────

    public function test_granted_and_revoked_both_receive_granted(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $telegramId = 'tg_gr';

        $clientA = $this->createTelegramClient($ws, $owner, $telegramId);
        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);

        $service = app(MarketingConsentService::class);
        $service->grant(
            client: $clientA, master: $owner, version: 'v1',
            consentText: 'Old.', source: 'telegram', channel: 'telegram',
            occurredAt: now()->subDays(2), idempotencyKey: 'key-a-old',
        );
        $service->revoke(
            client: $clientB, master: $owner,
            source: 'telegram', channel: 'telegram',
            occurredAt: now()->subDay(), idempotencyKey: 'key-b-revoke',
        );

        $pending = $this->createPending($clientA, $ws);
        $occurredAt = now();

        $result = $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, $telegramId, $occurredAt,
        );

        $this->assertSame(MarketingConsentGrantResult::Granted, $result);

        $stateA = $this->resolver->resolve($clientA);
        $stateB = $this->resolver->resolve($clientB);
        $this->assertSame(\App\Enums\MarketingConsentStatus::Granted, $stateA->status);
        $this->assertSame(\App\Enums\MarketingConsentStatus::Granted, $stateB->status);
    }

    // ── New Client after pending creation ──────────────────

    public function test_new_client_after_pending_creation(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $telegramId = 'tg_new';

        $clientA = $this->createTelegramClient($ws, $owner, $telegramId);
        $pending = $this->createPending($clientA, $ws);

        // Create B after pending
        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);

        $result = $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, $telegramId, now(),
        );

        $this->assertSame(MarketingConsentGrantResult::Granted, $result);

        $consents = ClientConsent::where('metadata->pending_marketing_consent_id', $pending->id)->get();
        $this->assertCount(2, $consents);
    }

    // ── Double call ───────────────────────────────────────

    public function test_double_call_returns_already_consumed(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_double');
        $pending = $this->createPending($client, $ws);
        $occurredAt = now();

        $first = $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_double', $occurredAt,
        );
        $this->assertSame(MarketingConsentGrantResult::Granted, $first);

        $second = $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_double', $occurredAt,
        );
        $this->assertSame(MarketingConsentGrantResult::AlreadyConsumed, $second);

        // No second set of events
        $consents = ClientConsent::where('client_id', $client->id)
            ->where('type', ConsentType::Marketing)
            ->get();
        $this->assertCount(1, $consents);
    }

    // ── Expired ───────────────────────────────────────────

    public function test_expired_pending_throws(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_exp');
        $pending = $this->createPending($client, $ws, [
            'expires_at' => now()->subMinute(),
        ]);

        $this->expectException(\DomainException::class);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_exp', now(),
        );

        $pending->refresh();
        $this->assertNull($pending->consumed_at);
        $this->assertCount(0, ClientConsent::where('client_id', $client->id)->get());
    }

    // ── Wrong provider ────────────────────────────────────

    public function test_wrong_provider_throws(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_right');
        $pending = $this->createPending($client, $ws);

        $this->expectException(\DomainException::class);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_wrong', now(),
        );

        $pending->refresh();
        $this->assertNull($pending->consumed_at);
    }

    // ── Wrong platform ────────────────────────────────────

    public function test_wrong_platform_throws(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_plat');
        $pending = $this->createPending($client, $ws, [
            'source' => 'telegram', 'channel' => 'telegram',
        ]);

        $this->expectException(\DomainException::class);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Max, 'max_123', now(),
        );

        $pending->refresh();
        $this->assertNull($pending->consumed_at);
    }

    // ── Channel mismatch ──────────────────────────────────

    public function test_channel_mismatch_throws(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_ch');
        $pending = $this->createPending($client, $ws, [
            'source' => 'telegram', 'channel' => 'max',
        ]);

        $this->expectException(\DomainException::class);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_ch', now(),
        );
    }

    // ── Downgrade ─────────────────────────────────────────

    public function test_downgrade_throws(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_down');
        $pending = $this->createPending($client, $ws);

        // Downgrade: expire Pro, add Start
        Subscription::where('workspace_id', $ws->id)->update(['expires_at' => now()->subDay()]);

        $startPlan = TariffPlan::where('code', 'start')->first();
        if (! $startPlan) {
            $startPlan = TariffPlan::create([
                'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
                'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
            ]);
        }

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1, 'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        $this->expectException(\DomainException::class);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_down', now(),
        );

        $pending->refresh();
        $this->assertNull($pending->consumed_at);
    }

    // ── Exact snapshot ────────────────────────────────────

    public function test_exact_snapshot_values(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_snap');
        $pending = $this->createPending($client, $ws, [
            'legal_version' => 'marketing-2026-09-01',
            'rendered_consent_text' => 'Вы соглашаетесь на рассылку от Beauty Studio.',
        ]);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_snap', now(),
        );

        $consent = ClientConsent::where('client_id', $client->id)->first();
        $this->assertEquals('marketing-2026-09-01', $consent->version);
        $this->assertEquals('Вы соглашаетесь на рассылку от Beauty Studio.', $consent->consent_text);
    }

    // ── occurredAt ────────────────────────────────────────

    public function test_occurred_at_propagated(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_time');
        $pending = $this->createPending($client, $ws);
        $occurredAt = Carbon::parse('2026-09-15 14:30:00');

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_time', $occurredAt,
        );

        $consent = ClientConsent::where('client_id', $client->id)->first();
        $this->assertEquals('2026-09-15 14:30:00', $consent->occurred_at->format('Y-m-d H:i:s'));

        $pending->refresh();
        $this->assertEquals('2026-09-15 14:30:00', $pending->consumed_at->format('Y-m-d H:i:s'));
    }

    // ── Canonical metadata override ───────────────────────

    public function test_canonical_metadata_override(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_meta');
        $pending = $this->createPending($client, $ws);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_meta', now(),
            metadata: [
                'pending_marketing_consent_id' => 'fake-pending-id',
                'workspace_id' => 'fake-workspace-id',
                'idempotency_key' => 'fake-key',
                'tg_callback_query_id' => 'cb_123',
            ],
        );

        $consent = ClientConsent::where('client_id', $client->id)->first();
        $metadata = $consent->metadata;

        $this->assertEquals($pending->id, $metadata['pending_marketing_consent_id']);
        $this->assertEquals($ws->id, $metadata['workspace_id']);
        $this->assertEquals("marketing-pending:{$pending->id}:{$client->id}", $metadata['idempotency_key']);
        $this->assertEquals('cb_123', $metadata['tg_callback_query_id']);
    }

    // ── Correct masters ───────────────────────────────────

    public function test_correct_master_per_client(): void
    {
        $ownerA = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $ownerA->id]);
        $ownerA->update(['workspace_id' => $ws->id]);

        $ownerB = User::factory()->master()->create();
        $ownerB->update(['workspace_id' => $ws->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        $telegramId = 'tg_masters';
        $clientA = Client::factory()->create([
            'user_id' => $ownerA->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);
        $clientB = Client::factory()->create([
            'user_id' => $ownerB->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);

        $pending = $this->createPending($clientA, $ws);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, $telegramId, now(),
        );

        $consentA = ClientConsent::where('client_id', $clientA->id)->first();
        $consentB = ClientConsent::where('client_id', $clientB->id)->first();

        $this->assertEquals($ownerA->id, $consentA->master_id);
        $this->assertEquals($ownerB->id, $consentB->master_id);
    }

    // ── Missing pending ───────────────────────────────────

    public function test_missing_pending_throws(): void
    {
        $this->expectException(\DomainException::class);

        $this->grantService->grantPending(
            Str::uuid()->toString(), AppointmentSource::Telegram, 'tg_123', now(),
        );
    }

    // ── No side effects ───────────────────────────────────

    public function test_no_side_effects_on_client_fields(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $client = $this->createTelegramClient($ws, $owner, 'tg_side');
        $client->update([
            'pdn_consent_at' => now()->subDays(30),
            'pdn_consent_version' => '1.0',
            'is_blocked' => false,
            'disable_reactivation' => false,
        ]);

        $pending = $this->createPending($client, $ws);

        $this->grantService->grantPending(
            $pending->id, AppointmentSource::Telegram, 'tg_side', now(),
        );

        $client->refresh();
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertEquals('1.0', $client->pdn_consent_version);
        $this->assertFalse($client->is_blocked);
        $this->assertFalse($client->disable_reactivation);
        $this->assertEquals('tg_side', $client->telegram_id);
    }
}
