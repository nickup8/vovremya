<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReactivationCandidateTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private TariffPlan $startPlan;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function createProWorkspace(): array
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id, 'role' => UserRole::Owner]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return [$owner, $workspace];
    }

    private function createCatalog(Workspace $ws, string $title = 'Массаж', ?int $reactivationDays = 21): ServiceCatalog
    {
        return ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => $title,
            'base_price' => 2000,
            'base_duration' => 60,
            'is_active' => true,
            'reactivation_days' => $reactivationDays,
        ]);
    }

    private function createMasterService(User $master, ServiceCatalog $catalog, bool $active = true): MasterService
    {
        return MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => $active,
        ]);
    }

    private function createPaidAppointment(User $master, Client $client, MasterService $ms, \DateTimeInterface $completedAt): Appointment
    {
        return Appointment::create([
            'master_id' => $master->id,
            'client_id' => $client->id,
            'master_service_id' => $ms->id,
            'start_time' => $completedAt->copy()->subHour(),
            'status' => AppointmentStatus::Paid,
            'completed_at' => $completedAt,
            'price' => 2000,
            'duration' => 60,
        ]);
    }

    // ── Basic candidate ───────────────────────────────────

    public function test_basic_candidate_found(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(22));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
        $this->assertEquals($client->id, $candidates[0]['client_id']);
        $this->assertEquals($catalog->id, $candidates[0]['service_catalog_id']);
        $this->assertEquals('Массаж', $candidates[0]['service_name']);
    }

    // ── Not due ───────────────────────────────────────────

    public function test_not_due_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(20));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Exact boundary ────────────────────────────────────

    public function test_exact_boundary_is_due(): void
    {
        $frozen = Carbon::create(2026, 9, 15, 12, 0, 0);
        Carbon::setTestNow($frozen);

        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $completedAt = $frozen->copy()->subDays(21);
        $this->createPaidAppointment($owner, $client, $ms, $completedAt);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);

        Carbon::setTestNow();
    }

    // ── Latest visit resets cycle ─────────────────────────

    public function test_latest_visit_resets_cycle(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(40));
        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(5));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Multiple paid visits, latest due ──────────────────

    public function test_multiple_paid_visits_one_candidate(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(50));
        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
        $this->assertEquals($client->id, $candidates[0]['client_id']);
    }

    // ── DISTINCT ON (client_id, catalog_id) correctness ──

    public function test_two_clients_same_catalog_both_due(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);

        $clientA = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Алиса']);
        $clientB = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Борис']);

        $this->createPaidAppointment($owner, $clientA, $ms, now()->subDays(25));
        $this->createPaidAppointment($owner, $clientB, $ms, now()->subDays(30));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(2, $candidates);
        $candidateClientIds = array_column($candidates, 'client_id');
        $this->assertContains($clientA->id, $candidateClientIds);
        $this->assertContains($clientB->id, $candidateClientIds);
    }

    // ── Multiple services ─────────────────────────────────

    public function test_one_client_two_services(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalogA = $this->createCatalog($ws, 'Массаж', 21);
        $catalogB = $this->createCatalog($ws, 'Маникюр', 14);
        $msA = $this->createMasterService($owner, $catalogA);
        $msB = $this->createMasterService($owner, $catalogB);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $msA, now()->subDays(25));
        $this->createPaidAppointment($owner, $client, $msB, now()->subDays(20));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(2, $candidates);
        $catalogIds = array_column($candidates, 'service_catalog_id');
        $this->assertContains($catalogA->id, $catalogIds);
        $this->assertContains($catalogB->id, $catalogIds);
    }

    // ── Null cycle ────────────────────────────────────────

    public function test_null_reactivation_days_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', null);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(30));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Blocked client ────────────────────────────────────

    public function test_blocked_client_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'is_blocked' => true,
        ]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Client opt-out ────────────────────────────────────

    public function test_opt_out_client_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id, 'disable_reactivation' => true,
        ]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── completed_at null ─────────────────────────────────

    public function test_null_completed_at_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => $client->id,
            'master_service_id' => $ms->id,
            'start_time' => now()->subDays(25),
            'status' => AppointmentStatus::Paid,
            'completed_at' => null,
            'price' => 2000,
            'duration' => 60,
        ]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Future same-service booked suppresses ─────────────

    public function test_future_booked_same_service_suppresses(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => $client->id,
            'master_service_id' => $ms->id,
            'start_time' => now()->addDays(3),
            'status' => AppointmentStatus::Booked,
            'price' => 2000,
            'duration' => 60,
        ]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Future pending_payment suppresses ─────────────────

    public function test_future_pending_payment_suppresses(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => $client->id,
            'master_service_id' => $ms->id,
            'start_time' => now()->addDays(3),
            'status' => AppointmentStatus::PendingPayment,
            'price' => 2000,
            'duration' => 60,
        ]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Future prepaid suppresses ─────────────────────────

    public function test_future_prepaid_suppresses(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => $client->id,
            'master_service_id' => $ms->id,
            'start_time' => now()->addDays(3),
            'status' => AppointmentStatus::Prepaid,
            'price' => 2000,
            'duration' => 60,
        ]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Different-service future does NOT suppress ────────

    public function test_different_service_future_does_not_suppress(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalogA = $this->createCatalog($ws, 'Массаж', 21);
        $catalogB = $this->createCatalog($ws, 'Маникюр', 14);
        $msA = $this->createMasterService($owner, $catalogA);
        $msB = $this->createMasterService($owner, $catalogB);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $msA, now()->subDays(25));

        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => $client->id,
            'master_service_id' => $msB->id,
            'start_time' => now()->addDays(3),
            'status' => AppointmentStatus::Booked,
            'price' => 1500,
            'duration' => 45,
        ]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
        $this->assertEquals($catalogA->id, $candidates[0]['service_catalog_id']);
    }

    // ── Inactive catalog ──────────────────────────────────

    public function test_inactive_catalog_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $catalog->update(['is_active' => false]);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Historical inactive MasterService, current active ─

    public function test_historical_inactive_ms_current_active_ok(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog, true);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        // Simulate: MS was deactivated then reactivated (same record, unique constraint)
        $ms->update(['is_active' => false]);
        $ms->update(['is_active' => true]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
    }

    // ── No current active offering ────────────────────────

    public function test_no_current_active_offering_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog, false);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Null master_service_id ────────────────────────────

    public function test_null_master_service_id_excluded(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        Appointment::create([
            'master_id' => $owner->id,
            'client_id' => $client->id,
            'master_service_id' => null,
            'start_time' => now()->subDays(25),
            'status' => AppointmentStatus::Paid,
            'completed_at' => now()->subDays(25),
            'price' => 2000,
            'duration' => 60,
        ]);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Cross-workspace isolation ─────────────────────────

    public function test_cross_workspace_isolation(): void
    {
        [$ownerA, $wsA] = $this->createProWorkspace();
        [$ownerB, $wsB] = $this->createProWorkspace();

        $catalogA = $this->createCatalog($wsA, 'Массаж', 21);
        $msA = $this->createMasterService($ownerA, $catalogA);
        $clientA = Client::factory()->create(['user_id' => $ownerA->id, 'workspace_id' => $wsA->id]);

        $this->createPaidAppointment($ownerA, $clientA, $msA, now()->subDays(25));

        $candidates = $this->actingAs($ownerB)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Start entitlement ─────────────────────────────────

    public function test_start_user_gets_403(): void
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'Start WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id, 'role' => UserRole::Owner]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->getJson('/admin/reactivation/candidates')
            ->assertForbidden();
    }

    // ── No consent regression ─────────────────────────────

    public function test_candidate_without_consent_present(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $this->assertEquals(0, \App\Models\ClientConsent::count());

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
    }

    // ── No channel regression ─────────────────────────────

    public function test_candidate_without_channel_present(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create([
            'user_id' => $owner->id, 'workspace_id' => $ws->id,
            'telegram_id' => null, 'max_id' => null,
        ]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
    }

    // ── DTO fields ────────────────────────────────────────

    public function test_dto_contains_all_required_fields(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Тест Клиент']);

        $completedAt = now()->subDays(25);
        $appointment = $this->createPaidAppointment($owner, $client, $ms, $completedAt);

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
        $c = $candidates[0];

        $this->assertArrayHasKey('client_id', $c);
        $this->assertArrayHasKey('client_name', $c);
        $this->assertArrayHasKey('service_catalog_id', $c);
        $this->assertArrayHasKey('service_name', $c);
        $this->assertArrayHasKey('source_appointment_id', $c);
        $this->assertArrayHasKey('last_visit_at', $c);
        $this->assertArrayHasKey('reactivation_days', $c);
        $this->assertArrayHasKey('eligible_at', $c);
        $this->assertArrayHasKey('days_overdue', $c);

        $this->assertEquals($client->id, $c['client_id']);
        $this->assertEquals('Тест Клиент', $c['client_name']);
        $this->assertEquals($catalog->id, $c['service_catalog_id']);
        $this->assertEquals('Массаж', $c['service_name']);
        $this->assertEquals($appointment->id, $c['source_appointment_id']);
        $this->assertEquals(21, $c['reactivation_days']);
        $this->assertGreaterThanOrEqual(0, $c['days_overdue']);
    }

    // ── Ordering: most overdue first ──────────────────────

    public function test_ordering_most_overdue_first(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);

        $clientA = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Алиса']);
        $clientB = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Борис']);

        $this->createPaidAppointment($owner, $clientA, $ms, now()->subDays(25));
        $this->createPaidAppointment($owner, $clientB, $ms, now()->subDays(40));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(2, $candidates);
        $this->assertGreaterThanOrEqual($candidates[1]['days_overdue'], $candidates[0]['days_overdue']);
    }

    // ── Large history: latest not due ─────────────────────

    public function test_large_history_latest_not_due(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        // 20 old paid visits, all due individually
        for ($i = 30; $i <= 100; $i += 5) {
            $this->createPaidAppointment($owner, $client, $ms, now()->subDays($i));
        }

        // Latest visit: 5 days ago — NOT due (cycle = 21)
        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(5));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(0, $candidates);
    }

    // ── Large history: latest due ─────────────────────────

    public function test_large_history_latest_due(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        // 20 old paid visits
        for ($i = 30; $i <= 100; $i += 5) {
            $this->createPaidAppointment($owner, $client, $ms, now()->subDays($i));
        }

        // Latest visit: 25 days ago — due (cycle = 21)
        $latestAppointment = $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $candidates = $this->actingAs($owner)->getJson('/admin/reactivation/candidates')->json();

        $this->assertCount(1, $candidates);
        $this->assertEquals($latestAppointment->id, $candidates[0]['source_appointment_id']);
    }

    // ── Query count regression ────────────────────────────

    public function test_single_candidate_query(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        // Pre-load user properties to avoid lazy-load noise
        $owner->workspace_id;
        $owner->id;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $service = app(\App\Services\Client\ReactivationCandidateService::class);
        $service->findFor($owner);

        $this->assertEquals(1, $queryCount);
    }

    // ── SQL contains DISTINCT ON ──────────────────────────

    public function test_sql_uses_distinct_on(): void
    {
        [$owner, $ws] = $this->createProWorkspace();
        $catalog = $this->createCatalog($ws, 'Массаж', 21);
        $ms = $this->createMasterService($owner, $catalog);
        $client = Client::factory()->create(['user_id' => $owner->id, 'workspace_id' => $ws->id]);

        $this->createPaidAppointment($owner, $client, $ms, now()->subDays(25));

        $capturedSql = '';
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql = $query->sql;
        });

        $service = app(\App\Services\Client\ReactivationCandidateService::class);
        $service->findFor($owner);

        $normalizedSql = strtolower($capturedSql);
        $this->assertStringContainsString('distinct on', $normalizedSql);
        $this->assertStringContainsString('not exists', $normalizedSql);
    }
}
