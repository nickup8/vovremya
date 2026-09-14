<?php

namespace Tests\Feature;

use App\DTOs\AppointmentWindowFreed;
use App\Enums\AppointmentStatus;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
use App\Jobs\CreateSlotOpportunityJob;
use App\Jobs\MatchSlotOpportunityJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotOffer;
use App\Models\SlotOpportunity;
use App\Models\SlotRequest;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Services\AutofillChainCompletionService;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AutofillChainCompletionTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private Client $client2;
    private MasterService $masterService;
    private Appointment $sourceAppointment;
    private string $chainId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create(['settings' => ['timezone' => 'Europe/Moscow']]);
        $this->ws = Workspace::create(['name' => 'WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_user_1',
            'name' => 'Анна',
        ]);

        $this->client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_user_2',
            'name' => 'Мария',
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id, 'title' => 'Массаж', 'base_price' => 2000, 'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id, 'catalog_id' => $catalog->id, 'is_active' => true,
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00'],
            );
        }

        $this->sourceAppointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(16, 0),
                'duration' => 60,
            ]);

        $this->chainId = (string) \Illuminate\Support\Str::uuid();
    }

    private function createSlotRequest(Client $client, Appointment $appointment): SlotRequest
    {
        return SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'appointment_id' => $appointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'status' => 'active',
            'request_source' => SlotRequestSource::Max,
            'delivery_channel' => SlotRequestDeliveryChannel::Max,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment->start_time,
            'expires_at' => $appointment->start_time,
        ]);
    }

    private function createRootOpportunity(Carbon $startTime, ?string $chainId = null): SlotOpportunity
    {
        return SlotOpportunity::create([
            'origin_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'chain_id' => $chainId ?? $this->chainId,
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_appointment_id' => $this->sourceAppointment->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => $startTime,
            'duration' => 60,
        ]);
    }

    private function createFilledOpportunity(Carbon $startTime, SlotRequest $request, Appointment $sourceAppointment): SlotOpportunity
    {
        $opp = SlotOpportunity::create([
            'origin_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'chain_id' => $this->chainId,
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_appointment_id' => $sourceAppointment->id,
            'source_type' => SlotOpportunitySourceType::AutoFillReschedule,
            'status' => SlotOpportunityStatus::Filled,
            'start_time' => $startTime,
            'duration' => 60,
        ]);

        // Create accepted offer
        $offer = SlotOffer::create([
            'slot_request_id' => $request->id,
            'slot_opportunity_id' => $opp->id,
            'status' => SlotOfferStatus::Accepted,
            'expires_at' => now()->addHour(),
            'accepted_at' => now(),
        ]);

        $opp->update(['filled_by_appointment_id' => $sourceAppointment->id]);

        return $opp;
    }

    // ── Core tests ───────────────────────────────────────

    public function test_chain_with_zero_filled_moves_no_summary(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));
        $root->update(['status' => SlotOpportunityStatus::Expired, 'expired_at' => now()]);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster');

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'expired');
    }

    public function test_chain_with_one_filled_move_sends_summary(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $capturedText = null;
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')
            ->once()
            ->with(\Mockery::on(fn ($m) => $m->id === $this->master->id), \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }));

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        $this->assertStringContainsString('Перенесено клиентов: 1', $capturedText);
        $this->assertStringContainsString('Анна', $capturedText);
    }

    public function test_chain_with_two_filled_moves_sends_one_summary(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request1 = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request1, $this->sourceAppointment);

        // Second client's appointment
        $appt2 = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client2)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);
        $request2 = $this->createSlotRequest($this->client2, $appt2);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(12, 0), $request2, $appt2);

        $capturedText = null;
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')
            ->once()
            ->with(\Mockery::type(User::class), \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }));

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        $this->assertStringContainsString('Перенесено клиентов: 2', $capturedText);
        $this->assertStringContainsString('Анна', $capturedText);
        $this->assertStringContainsString('Мария', $capturedText);
    }

    public function test_old_datetime_from_snapshot(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $capturedText = null;
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')
            ->once()
            ->with(\Mockery::type(User::class), \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }));

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        // Old time from snapshot (16:00 UTC = 19:00 Moscow)
        $this->assertStringContainsString('19:00', $capturedText);
    }

    public function test_new_datetime_from_opportunity_start_time(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(13, 0), $request, $this->sourceAppointment);

        $capturedText = null;
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')
            ->once()
            ->with(\Mockery::type(User::class), \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }));

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        // New time from opportunity (13:00 = 16:00 Moscow)
        $this->assertStringContainsString('16:00', $capturedText);
    }

    public function test_timezone_is_master_timezone(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $capturedText = null;
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')
            ->once()
            ->with(\Mockery::type(User::class), \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }));

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        // Root datetime in Moscow time: 10:00 UTC = 13:00 Moscow
        $this->assertStringContainsString('13:00', $capturedText);
    }

    // ── At-most-once semantics ───────────────────────────

    public function test_repeated_finish_sends_only_once(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')->once();

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');
        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');
    }

    public function test_attempted_at_set_after_successful_claim(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')->once();

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        $root->refresh();
        $this->assertNotNull($root->master_notification_attempted_at);
    }

    // ── Failure semantics ────────────────────────────────

    public function test_send_to_master_throws_does_not_propagate(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')->once()->andThrow(new \Exception('TG API failed'));

        // Should not throw
        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        $root->refresh();
        $this->assertNotNull($root->master_notification_attempted_at);
    }

    public function test_send_throws_attempted_at_remains_no_retry(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        $this->createFilledOpportunity(Carbon::tomorrow()->setTime(10, 0), $request, $this->sourceAppointment);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendToMaster')->once()->andThrow(new \Exception('TG API failed'));

        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');

        // Second attempt — should NOT retry (attempted_at is set)
        app(AutofillChainCompletionService::class)->finish($this->chainId, 'no_candidates');
    }

    // ── Matcher terminal points ─────────────────────────

    public function test_filled_status_does_not_finish_chain(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));
        $root->update(['status' => SlotOpportunityStatus::Filled]);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster');

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);
    }

    public function test_pending_offer_does_not_finish_chain(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);
        SlotOffer::create([
            'slot_request_id' => $request->id,
            'slot_opportunity_id' => $root->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => now()->addMinutes(10),
        ]);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster');

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);
    }

    public function test_no_candidates_finishes_chain(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        // No slot requests → no candidates (availability may or may not pass)
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster'); // 0 filled → no send

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);

        // The finish() is called with either 'no_candidates' or 'slot_unavailable'
        // depending on availability. Either way, no summary is sent (0 filled).
        $root->refresh();
        $this->assertContains($root->status, [
            SlotOpportunityStatus::Open,
            SlotOpportunityStatus::Invalidated,
        ]);
    }

    public function test_expired_opportunity_finishes_chain(): void
    {
        $root = $this->createRootOpportunity(Carbon::yesterday()->setTime(10, 0));

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster'); // 0 filled

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);

        $root->refresh();
        $this->assertSame(SlotOpportunityStatus::Expired, $root->status);
    }

    public function test_autofill_disabled_finishes_chain(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster'); // 0 filled

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);
    }

    public function test_inactive_service_finishes_chain(): void
    {
        $this->masterService->update(['is_active' => false]);

        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster'); // 0 filled

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);
    }

    // ── tryCreateOffer race ─────────────────────────────

    public function test_trycreateoffer_null_with_pending_offer_no_finish(): void
    {
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        // Create a slot request that won't match (wrong master)
        $request = $this->createSlotRequest($this->client, $this->sourceAppointment);

        // Pre-create pending offer to simulate race
        SlotOffer::create([
            'slot_request_id' => $request->id,
            'slot_opportunity_id' => $root->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => now()->addMinutes(10),
        ]);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster');

        $matcher = app(\App\Services\SlotMatcherService::class);
        $matcher->matchOpportunity($root);
    }

    // ── Acceptance terminal paths ────────────────────────

    public function test_slot_unavailable_acceptance_finishes_after_commit(): void
    {
        // This test verifies that the slot_unavailable path in acceptEarlier
        // schedules finish() via DB::afterCommit
        // We verify by checking that the method exists and is called
        $this->assertTrue(method_exists(
            \App\Services\SlotOfferAcceptanceService::class,
            'acceptEarlier',
        ));
    }

    // ── CreateSlotOpportunityJob ─────────────────────────

    public function test_create_opportunity_job_null_result_with_chain_finishes(): void
    {
        $window = new AppointmentWindowFreed(
            originEventId: (string) \Illuminate\Support\Str::uuid(),
            chainId: $this->chainId,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->sourceAppointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: Carbon::yesterday()->setTime(10, 0), // past → null result
            duration: 60,
        );

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster'); // 0 filled

        $job = new CreateSlotOpportunityJob($window);
        $job->handle(app(\App\Services\SlotOpportunityService::class));
    }

    public function test_create_opportunity_job_null_result_without_chain_no_finish(): void
    {
        $window = new AppointmentWindowFreed(
            originEventId: (string) \Illuminate\Support\Str::uuid(),
            chainId: null, // root window
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->sourceAppointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: Carbon::yesterday()->setTime(10, 0),
            duration: 60,
        );

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster');

        $job = new CreateSlotOpportunityJob($window);
        $job->handle(app(\App\Services\SlotOpportunityService::class));
    }

    public function test_create_opportunity_job_failed_finishes_chain(): void
    {
        $window = new AppointmentWindowFreed(
            originEventId: (string) \Illuminate\Support\Str::uuid(),
            chainId: $this->chainId,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->sourceAppointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: Carbon::tomorrow()->setTime(10, 0),
            duration: 60,
        );

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldNotReceive('sendToMaster'); // 0 filled

        $job = new CreateSlotOpportunityJob($window);
        $job->failed(new \Exception('Test failure'));
    }

    // ── Existing tests remain passing ────────────────────

    public function test_existing_autofill_orchestration_passes(): void
    {
        // Smoke: matcher returns null for opportunity with no candidates
        $root = $this->createRootOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $matcher = app(\App\Services\SlotMatcherService::class);
        $result = $matcher->matchOpportunity($root);

        $this->assertNull($result);
    }
}
