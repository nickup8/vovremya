<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
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
use App\Services\MaxApiClient;
use App\Webhooks\MaxWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MaxAutofillCallbackTest extends TestCase
{
    use RefreshDatabase;

    private MaxApiClient $maxApi;
    private MaxWebhookHandler $handler;
    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private Client $otherClient;
    private MasterService $masterService;
    private Appointment $sourceAppointment;
    private SlotRequest $request;
    private SlotOpportunity $opportunity;
    private SlotOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $this->maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $this->maxApi);

        $this->handler = app(MaxWebhookHandler::class);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create(['settings' => ['timezone' => 'Europe/Moscow']]);
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_user_123',
        ]);

        $this->otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_user_999',
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                [
                    'is_working' => true,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                    'break_start_time' => null,
                    'break_end_time' => null,
                ],
            );
        }

        $this->sourceAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        $this->request = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => $this->sourceAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Max,
            'delivery_channel' => SlotRequestDeliveryChannel::Max,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $this->sourceAppointment->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(13, 59),
        ]);

        $this->opportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
        ]);

        $this->offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);
    }

    private function invokeAccept(string $userId, string $callbackId, string $offerId): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'handleAutofillAccept');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $callbackId, $offerId);
    }

    private function invokeDecline(string $userId, string $callbackId, string $offerId): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'handleAutofillDecline');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $callbackId, $offerId);
    }

    // ── Accept callback ───────────────────────────────────

    public function test_accept_callback_moves_appointment(): void
    {
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->with(Mockery::any(), 'Готово, запись перенесена.')
            ->andReturn(true);

        $this->invokeAccept($this->client->max_id, 'cb_test', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
        $this->assertSame(SlotRequestStatus::Fulfilled, $this->request->fresh()->status);
        $this->assertSame(SlotOpportunityStatus::Filled, $this->opportunity->fresh()->status);
    }

    public function test_accept_success_uses_answer_callback_with_message(): void
    {
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->with(Mockery::any(), 'Готово, запись перенесена.')
            ->andReturn(true);

        // answerCallback (without message) should not be the primary path
        $this->maxApi->shouldReceive('answerCallback')->never();

        $this->invokeAccept($this->client->max_id, 'cb_test', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
    }

    // ── Ownership ─────────────────────────────────────────

    public function test_wrong_max_user_cannot_accept(): void
    {
        $this->maxApi->shouldReceive('answerCallback')
            ->once()
            ->with('cb_test', 'Предложение недоступно')
            ->andReturn(true);

        $this->invokeAccept('wrong_user_id', 'cb_test', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Pending, $this->offer->fresh()->status);
    }

    public function test_wrong_max_user_cannot_decline(): void
    {
        $this->maxApi->shouldReceive('answerCallback')
            ->once()
            ->with('cb_test', 'Предложение недоступно')
            ->andReturn(true);

        $this->invokeDecline('wrong_user_id', 'cb_test', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Pending, $this->offer->fresh()->status);
    }

    // ── Slot taken ────────────────────────────────────────

    public function test_slot_taken_gives_correct_message(): void
    {
        $blocker = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($blocker)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->with(Mockery::any(), 'Это время уже заняли. Продолжим искать подходящее время.')
            ->andReturn(true);

        $this->invokeAccept($this->client->max_id, 'cb_test', $this->offer->id);
    }

    // ── Decline callback ──────────────────────────────────

    public function test_decline_callback_sets_declined_and_remaches(): void
    {
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->with(Mockery::any(), 'Хорошо, это время не подойдёт. Продолжим искать.')
            ->andReturn(true);

        $this->invokeDecline($this->client->max_id, 'cb_test', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Declined, $this->offer->fresh()->status);
        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);

        Bus::assertDispatched(MatchSlotOpportunityJob::class, function ($job) {
            return $job->slotOpportunityId === $this->opportunity->id;
        });
    }

    // ── Stale/non-pending callbacks ───────────────────────

    public function test_accepted_offer_callback_is_idempotent(): void
    {
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->andReturn(true);

        $this->invokeAccept($this->client->max_id, 'cb_test', $this->offer->id);
        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);

        // Second accept — idempotent, no second move
        $this->invokeAccept($this->client->max_id, 'cb_test_2', $this->offer->id);

        $moved = $this->sourceAppointment->fresh();
        $this->assertEquals(
            Carbon::tomorrow()->setTime(10, 0)->format('Y-m-d H:i'),
            $moved->start_time->format('Y-m-d H:i'),
        );
    }

    public function test_decline_on_already_accepted_offer_returns_message(): void
    {
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->andReturn(true);

        $this->invokeAccept($this->client->max_id, 'cb_accept', $this->offer->id);
        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);

        // Decline on accepted offer
        $this->invokeDecline($this->client->max_id, 'cb_decline', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
    }

    public function test_nonexistent_offer_returns_unavailable(): void
    {
        $fakeUuid = (string) Str::uuid();

        $this->maxApi->shouldReceive('answerCallback')
            ->once()
            ->with('cb_test', 'Предложение недоступно')
            ->andReturn(true);

        $this->invokeAccept($this->client->max_id, 'cb_test', $fakeUuid);
    }

    // ── Duplicate accept ──────────────────────────────────

    public function test_duplicate_accept_is_safe_noop(): void
    {
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->andReturn(true);

        $this->invokeAccept($this->client->max_id, 'cb_1', $this->offer->id);
        $this->invokeAccept($this->client->max_id, 'cb_2', $this->offer->id);

        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
    }
}
