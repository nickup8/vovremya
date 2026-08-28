<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
use App\Jobs\MatchSlotOpportunityJob;
use App\Jobs\SendMaxSlotOfferJob;
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
use App\Services\SlotOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SendMaxSlotOfferJobTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $sourceAppointment;
    private SlotRequest $request;
    private SlotOpportunity $opportunity;
    private SlotOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

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
            'origin_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'chain_id' => (string) \Illuminate\Support\Str::uuid(),
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

    // ── Delivery ──────────────────────────────────────────

    public function test_pending_max_offer_sends_message_with_two_buttons(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);

        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn ($text) => str_contains($text, 'Освободилось время раньше')
                    && str_contains($text, 'Массаж')
                    && str_contains($text, 'Можно перенести на:')
                    && str_contains($text, 'Перенести запись?')),
                Mockery::on(function ($extra) {
                    $buttons = $extra['attachments'][0]['payload']['buttons'] ?? [];
                    return count($buttons) === 2
                        && $buttons[0][0]['payload'] === 'af_accept_' . $this->offer->id
                        && $buttons[0][0]['text'] === 'Перенести'
                        && $buttons[1][0]['payload'] === 'af_decline_' . $this->offer->id
                        && $buttons[1][0]['text'] === 'Не подходит';
                }),
            )
            ->andReturn('msg_123');

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));
    }

    public function test_message_contains_old_and_new_local_times(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);

        $capturedText = null;
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function ($chatId, $text, $extra) use (&$capturedText) {
                $capturedText = $text;
                return 'msg_123';
            });

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $tz = 'Europe/Moscow';
        $oldLocal = Carbon::tomorrow()->setTime(14, 0)->timezone($tz)->format('d.m.Y H:i');
        $newLocal = Carbon::tomorrow()->setTime(10, 0)->timezone($tz)->format('d.m.Y H:i');

        $this->assertStringContainsString("Было: {$oldLocal}", $capturedText);
        $this->assertStringContainsString("Можно перенести на: {$newLocal}", $capturedText);
    }

    // ── Guards ────────────────────────────────────────────

    public function test_non_pending_offer_skipped(): void
    {
        $this->offer->update([
            'status' => SlotOfferStatus::Expired,
            'expired_at' => now(),
        ]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));
    }

    public function test_expired_offer_skipped(): void
    {
        $this->offer->update(['expires_at' => Carbon::yesterday()]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));
    }

    public function test_missing_max_id_invalidates_and_rematches(): void
    {
        $this->client->update(['max_id' => null]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $this->assertSame(SlotOfferStatus::Invalidated, $this->offer->fresh()->status);
        Bus::assertDispatched(MatchSlotOpportunityJob::class, function ($job) {
            return $job->slotOpportunityId === $this->opportunity->id;
        });
    }

    public function test_max_id_present_max_chat_id_null_sends_successfully(): void
    {
        $this->client->update(['max_chat_id' => null]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->with('max_user_123', Mockery::any(), Mockery::any())
            ->andReturn('msg_123');

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $this->assertSame(SlotOfferStatus::Pending, $this->offer->fresh()->status);
    }

    public function test_max_api_failure_throws_for_retry(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->once()->andReturn(null);

        $job = new SendMaxSlotOfferJob($this->offer->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MAX API failed to send slot offer message');

        $job->handle($maxApi, app(SlotOfferService::class));
    }

    public function test_non_max_channel_invalidates(): void
    {
        $this->request->update(['delivery_channel' => SlotRequestDeliveryChannel::Telegram]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $this->assertSame(SlotOfferStatus::Invalidated, $this->offer->fresh()->status);
    }

    // ── Failed handler (final retry exhaustion) ───────────

    public function test_failed_handler_invalidates_and_remaches(): void
    {
        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->failed(new \Exception('transient'));

        $this->assertSame(SlotOfferStatus::Invalidated, $this->offer->fresh()->status);
        Bus::assertDispatched(MatchSlotOpportunityJob::class, function ($job) {
            return $job->slotOpportunityId === $this->opportunity->id;
        });
    }

    public function test_failed_handler_noop_on_already_accepted(): void
    {
        $this->offer->update([
            'status' => SlotOfferStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->failed(new \Exception('transient'));

        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
        Bus::assertNotDispatched(MatchSlotOpportunityJob::class);
    }
}
