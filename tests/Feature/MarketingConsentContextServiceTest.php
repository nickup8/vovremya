<?php

namespace Tests\Feature;

use App\Enums\AppointmentSource;
use App\Enums\SubscriptionStatus;
use App\Models\Client;
use App\Models\PendingMarketingConsent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Consent\MarketingConsentContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingConsentContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingConsentContextService $service;
    private TariffPlan $proPlan;
    private TariffPlan $startPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketingConsentContextService;

        $this->startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'client_reactivation'],
            'is_active' => true,
        ]);
    }

    private function createWorkspaceWithPro(): array
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

    private function createWorkspaceWithStart(): array
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return [$owner, $ws];
    }

    private function createClientWithTelegram(Workspace $ws, User $owner, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'user_id' => $owner->id,
            'workspace_id' => $ws->id,
            'telegram_id' => 'tg_' . Str::random(8),
        ], $overrides));
    }

    private function createClientWithMax(Workspace $ws, User $owner, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'user_id' => $owner->id,
            'workspace_id' => $ws->id,
            'max_id' => 'max_' . Str::random(8),
        ], $overrides));
    }

    // ── Telegram one Pro Workspace ────────────────────────

    public function test_telegram_one_pro_workspace(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(1, $contexts);
        $this->assertEquals($ws->id, $contexts->first()->workspace->id);
        $this->assertEquals($client->id, $contexts->first()->representativeClient->id);
    }

    // ── Multiple Workspaces ───────────────────────────────

    public function test_multiple_workspaces(): void
    {
        $telegramId = 'tg_shared_' . Str::random(6);

        [$owner1, $ws1] = $this->createWorkspaceWithPro();
        [$owner2, $ws2] = $this->createWorkspaceWithPro();
        [$owner3, $ws3] = $this->createWorkspaceWithPro();

        Client::factory()->create([
            'user_id' => $owner1->id, 'workspace_id' => $ws1->id, 'telegram_id' => $telegramId,
        ]);
        Client::factory()->create([
            'user_id' => $owner2->id, 'workspace_id' => $ws2->id, 'telegram_id' => $telegramId,
        ]);
        Client::factory()->create([
            'user_id' => $owner3->id, 'workspace_id' => $ws3->id, 'telegram_id' => $telegramId,
        ]);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $telegramId,
        );

        $this->assertCount(3, $contexts);
    }

    // ── Duplicate Clients same Workspace ──────────────────

    public function test_duplicate_clients_same_workspace(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $telegramId = 'tg_dup_' . Str::random(6);

        $clientA = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => $telegramId, 'created_at' => now()->subDays(2),
        ]);
        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => $telegramId, 'created_at' => now()->subDay(),
        ]);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $telegramId,
        );

        $this->assertCount(1, $contexts);
        $this->assertEquals($clientA->id, $contexts->first()->representativeClient->id);
    }

    // ── Start plan excluded ───────────────────────────────

    public function test_start_plan_excluded(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithStart();
        $client = $this->createClientWithTelegram($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(0, $contexts);
    }

    // ── No subscription ───────────────────────────────────

    public function test_no_subscription_excluded(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id]);
        $client = $this->createClientWithTelegram($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(0, $contexts);
    }

    // ── Expired Pro ───────────────────────────────────────

    public function test_expired_pro_excluded(): void
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
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subDay(),
        ]);

        $client = $this->createClientWithTelegram($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(0, $contexts);
    }

    // ── Overlapping subscriptions #1: Pro expires sooner, Start later ──

    public function test_overlapping_pro_earlier_start_later_excluded(): void
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
            'expires_at' => now()->addDays(10),
        ]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $client = $this->createClientWithTelegram($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(0, $contexts);
    }

    // ── Overlapping subscriptions #2: Start expires sooner, Pro later ──

    public function test_overlapping_start_earlier_pro_later_grantable(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $ws->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(10),
        ]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);

        $client = $this->createClientWithTelegram($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(1, $contexts);
    }

    // ── Provider isolation ────────────────────────────────

    public function test_provider_isolation(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $telegramId = 'tg_iso_' . Str::random(6);
        $otherTelegramId = 'tg_other_' . Str::random(6);

        Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
        ]);
        Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $otherTelegramId,
        ]);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $telegramId,
        );

        $this->assertCount(1, $contexts);
    }

    // ── Null workspace excluded ───────────────────────────

    public function test_null_workspace_excluded(): void
    {
        $owner = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => null,
            'telegram_id' => 'tg_null_' . Str::random(6),
        ]);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertCount(0, $contexts);
    }

    // ── validateSelectedClient success ────────────────────

    public function test_validate_selected_client_success(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        $result = $this->service->validateSelectedClient(
            AppointmentSource::Telegram,
            $client->telegram_id,
            $client->id,
        );

        $this->assertEquals($client->id, $result->id);
        $this->assertEquals($ws->id, $result->workspace->id);
    }

    // ── validateSelectedClient wrong provider ─────────────

    public function test_validate_selected_client_wrong_provider(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        $this->expectException(\DomainException::class);

        $this->service->validateSelectedClient(
            AppointmentSource::Telegram,
            'tg_wrong_' . Str::random(6),
            $client->id,
        );
    }

    // ── Unsupported platform ──────────────────────────────

    public function test_unsupported_platform(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->findGrantableContexts(
            AppointmentSource::Admin,
            '123',
        );
    }

    // ── Downgrade: Pro → Start ────────────────────────────

    public function test_downgrade_rejects_validation(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        // Downgrade: expire Pro, add Start
        Subscription::where('workspace_id', $ws->id)->update([
            'expires_at' => now()->subDay(),
        ]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->expectException(\DomainException::class);

        $this->service->validateSelectedClient(
            AppointmentSource::Telegram,
            $client->telegram_id,
            $client->id,
        );
    }

    // ── clientsForWorkspace ───────────────────────────────

    public function test_clients_for_workspace(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $telegramId = 'tg_cfw_' . Str::random(6);

        $clientA = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => $telegramId, 'created_at' => now()->subDays(2),
        ]);
        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => $telegramId, 'created_at' => now()->subDay(),
        ]);

        // Different workspace
        [$owner2, $ws2] = $this->createWorkspaceWithPro();
        Client::factory()->create([
            'user_id' => $owner2->id, 'workspace_id' => $ws2->id, 'telegram_id' => $telegramId,
        ]);

        $clients = $this->service->clientsForWorkspace(
            AppointmentSource::Telegram,
            $telegramId,
            $ws->id,
        );

        $this->assertCount(2, $clients);
        $this->assertEquals($clientA->id, $clients->first()->id);
        $this->assertEquals($clientB->id, $clients->last()->id);
    }

    // ── Fresh duplicate after screen ──────────────────────

    public function test_fresh_duplicate_returns_both(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $telegramId = 'tg_fresh_' . Str::random(6);

        $clientA = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => $telegramId, 'created_at' => now()->subDay(),
        ]);

        $first = $this->service->clientsForWorkspace(
            AppointmentSource::Telegram, $telegramId, $ws->id,
        );
        $this->assertCount(1, $first);

        $clientB = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => $telegramId, 'created_at' => now(),
        ]);

        $second = $this->service->clientsForWorkspace(
            AppointmentSource::Telegram, $telegramId, $ws->id,
        );
        $this->assertCount(2, $second);
    }

    // ── MAX parity ────────────────────────────────────────

    public function test_max_provider_mapping(): void
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

        $client = $this->createClientWithMax($ws, $owner);

        $contexts = $this->service->findGrantableContexts(
            AppointmentSource::Max,
            $client->max_id,
        );

        $this->assertCount(1, $contexts);
        $this->assertEquals($client->id, $contexts->first()->representativeClient->id);
    }

    // ── Query count: 1 Workspace ──────────────────────────

    public function test_query_count_one_workspace(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $client->telegram_id,
        );

        $this->assertLessThanOrEqual(3, $queryCount);
    }

    // ── Query count: 5 Workspaces ─────────────────────────

    public function test_query_count_five_workspaces(): void
    {
        $telegramId = 'tg_qc_' . Str::random(6);

        for ($i = 0; $i < 5; $i++) {
            [$owner, $ws] = $this->createWorkspaceWithPro();
            Client::factory()->create([
                'user_id' => $owner->id, 'workspace_id' => $ws->id, 'telegram_id' => $telegramId,
            ]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->service->findGrantableContexts(
            AppointmentSource::Telegram,
            $telegramId,
        );

        $this->assertLessThanOrEqual(3, $queryCount);
    }

    // ── validatePending success ───────────────────────────

    public function test_validate_pending_success(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        $pending = PendingMarketingConsent::create([
            'client_id' => $client->id,
            'workspace_id' => $ws->id,
            'legal_version' => 'test-v1',
            'rendered_consent_text' => 'Test consent text.',
            'source' => 'telegram',
            'channel' => 'telegram',
            'shown_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $result = $this->service->validatePending(
            AppointmentSource::Telegram,
            $client->telegram_id,
            $pending,
        );

        $this->assertEquals($client->id, $result->id);
    }

    // ── validatePending workspace mismatch ────────────────

    public function test_validate_pending_workspace_mismatch(): void
    {
        [$owner, $ws] = $this->createWorkspaceWithPro();
        [$owner2, $ws2] = $this->createWorkspaceWithPro();
        $client = $this->createClientWithTelegram($ws, $owner);

        $pending = PendingMarketingConsent::create([
            'client_id' => $client->id,
            'workspace_id' => $ws2->id,
            'legal_version' => 'test-v1',
            'rendered_consent_text' => 'Test consent text.',
            'source' => 'telegram',
            'channel' => 'telegram',
            'shown_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->expectException(\DomainException::class);

        $this->service->validatePending(
            AppointmentSource::Telegram,
            $client->telegram_id,
            $pending,
        );
    }
}
