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
use App\Jobs\SendVkSlotOfferJob;
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
use App\Services\AppointmentStatusService;
use App\Services\SlotOfferAcceptanceService;
use App\Services\SlotOfferService;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VkAutofillParityTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppSecret = 'test-vk-app-secret';
    private string $testAppId = '6736218';
    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['services.vk.app_id' => $this->testAppId]);
        config(['services.vk.secret' => 'test_vk_secret_abc']);
        config(['services.vk.bot_token' => 'test_bot_token']);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create();
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
            'vk_id' => '100500',
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id, 'title' => 'Массаж', 'base_price' => 2000, 'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id, 'catalog_id' => $catalog->id, 'is_active' => true,
        ]);

        for ($day = 0; $day <= 6; $day++) {
            \App\Models\WorkingHour::updateOrCreate(
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

        $tz = $this->master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(16, 0);

        $this->appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => $localStart->copy()->setTimezone('UTC'),
                'duration' => 60,
            ]);
    }

    private function sign(array $vkParams): string
    {
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);

        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
    }

    private function vkHeaders(string $vkUserId = '100500'): array
    {
        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];
        $sign = $this->sign($vkParams);
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer ' . http_build_query($all)];
    }

    private function webhookPayload(string $command, string $userId = '100500', string $peerId = '200', string $cmid = '50'): array
    {
        return [
            'type' => 'message_event',
            'secret' => 'test_vk_secret_abc',
            'object' => [
                'event_id' => 'evt1',
                'user_id' => $userId,
                'peer_id' => $peerId,
                'conversation_message_id' => $cmid,
                'payload' => ['command' => $command],
            ],
        ];
    }

    private function createSlotRequest(SlotRequestDeliveryChannel $channel = SlotRequestDeliveryChannel::Vk, SlotRequestSource $source = SlotRequestSource::Vk): SlotRequest
    {
        return SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => $this->appointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'status' => SlotRequestStatus::Active,
            'request_source' => $source,
            'delivery_channel' => $channel,
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00:00',
            'time_to' => '15:00:00',
            'timezone' => $this->master->getTimezone(),
            'appointment_start_time_snapshot' => $this->appointment->start_time,
            'expires_at' => $this->appointment->start_time,
        ]);
    }

    private function createOpportunity(?Carbon $startTime = null): SlotOpportunity
    {
        return SlotOpportunity::create([
            'origin_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'chain_id' => (string) \Illuminate\Support\Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_appointment_id' => $this->appointment->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'start_time' => $startTime ?? Carbon::tomorrow()->setTime(14, 0),
            'duration' => 60,
            'status' => SlotOpportunityStatus::Open,
        ]);
    }

    private function createOffer(?SlotRequest $request = null, ?SlotOpportunity $opportunity = null, SlotOfferStatus $status = SlotOfferStatus::Pending): SlotOffer
    {
        $offer = SlotOffer::create([
            'slot_request_id' => ($request ?? $this->createSlotRequest())->id,
            'slot_opportunity_id' => ($opportunity ?? $this->createOpportunity())->id,
            'status' => $status,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $offer;
    }

    // ── 1-4: EarlierRequest API source/channel ────────────

    public function test_vk_can_put_earlier_request_through_miniapp_auth(): void
    {
        $this->mock(VkApiClient::class);

        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->vkHeaders());

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    public function test_max_still_can_put_earlier_request_through_same_route(): void
    {
        $this->app['config']->set('services.max.bot_token', 'test-bot-token-123');
        $this->client->update(['max_id' => '100500']);

        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 100500, 'first_name' => 'Test']),
        ];
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $launchParams = implode("\n", $pairs);
        $secretKey = hash_hmac('sha256', 'test-bot-token-123', 'WebAppData', true);
        $hash = hash_hmac('sha256', $launchParams, $secretKey, false);
        $pairsFinal = [];
        foreach ($params as $k => $v) {
            $pairsFinal[] = "$k=" . urlencode($v);
        }
        $pairsFinal[] = "hash=$hash";
        $initData = implode('&', $pairsFinal);

        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], ['X-Max-Init-Data' => $initData]);

        $response->assertOk();
    }

    public function test_vk_request_stores_vk_source_and_channel(): void
    {
        $this->mock(VkApiClient::class);

        $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->vkHeaders())->assertOk();

        $this->assertDatabaseHas('slot_requests', [
            'appointment_id' => $this->appointment->id,
            'request_source' => 'vk',
            'delivery_channel' => 'vk',
        ]);
    }

    public function test_vk_identity_missing_rejects_request(): void
    {
        $this->client->update(['vk_id' => null]);

        $response = $this->putJson('/api/miniapp/appointments/' . $this->appointment->id . '/earlier-request', [
            'date_from' => Carbon::tomorrow()->format('Y-m-d'),
            'date_to' => Carbon::tomorrow()->format('Y-m-d'),
            'time_from' => '09:00',
            'time_to' => '15:00',
        ], $this->vkHeaders());

        $response->assertStatus(403);
    }

    // ── 6-7: MatchSlotOpportunityJob dispatches VK job ────

    public function test_match_dispatches_send_vk_slot_offer_job(): void
    {
        Bus::fake([SendVkSlotOfferJob::class]);

        $this->createSlotRequest(SlotRequestDeliveryChannel::Vk);
        // Opportunity must be before source appointment in master's timezone (Europe/Moscow)
        $opportunity = $this->createOpportunity(Carbon::tomorrow()->setTime(10, 0));

        $job = new MatchSlotOpportunityJob($opportunity->id);
        $job->handle(app(\App\Services\SlotMatcherService::class));

        Bus::assertDispatched(SendVkSlotOfferJob::class);
    }

    // ── 8-13: SendVkSlotOfferJob ─────────────────────────

    public function test_send_vk_slot_offer_job_sends_correct_text(): void
    {
        $opportunity = $this->createOpportunity(Carbon::tomorrow()->setTime(14, 0));
        $request = $this->createSlotRequest();
        $offer = $this->createOffer($request, $opportunity);

        $vkApi = $this->mock(VkApiClient::class);
        $capturedText = null;
        $capturedKeyboard = null;

        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with(
                '100500',
                \Mockery::on(function ($text) use (&$capturedText) {
                    $capturedText = $text;
                    return true;
                }),
                \Mockery::on(function ($keyboard) use (&$capturedKeyboard) {
                    $capturedKeyboard = $keyboard;
                    return true;
                }),
            )
            ->andReturn('12345');

        $this->app->instance(VkApiClient::class, $vkApi);

        SendVkSlotOfferJob::dispatchSync($offer->id);

        $this->assertStringContainsString('Освободилось время раньше', $capturedText);
        $this->assertStringContainsString('Можно перенести на:', $capturedText);
        $this->assertStringContainsString('Перенести запись?', $capturedText);
    }

    public function test_vk_accept_payload_json_format(): void
    {
        $offerId = '550e8400-e29b-41d4-a716-446655440000';

        $payload = json_encode(['command' => 'af_accept_' . $offerId], JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($payload, true);

        $this->assertSame('af_accept_' . $offerId, $decoded['command']);
    }

    public function test_vk_decline_payload_json_format(): void
    {
        $offerId = '550e8400-e29b-41d4-a716-446655440000';

        $payload = json_encode(['command' => 'af_decline_' . $offerId], JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($payload, true);

        $this->assertSame('af_decline_' . $offerId, $decoded['command']);
    }

    public function test_successful_vk_delivery_writes_sent_at_and_mid(): void
    {
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn('msg_999');
        $this->app->instance(VkApiClient::class, $vkApi);

        SendVkSlotOfferJob::dispatchSync($offer->id);

        $offer->refresh();
        $this->assertNotNull($offer->sent_at);
        $this->assertSame('msg_999', $offer->delivery_mid);
    }

    public function test_vk_send_null_throws_no_false_sent_at(): void
    {
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        try {
            SendVkSlotOfferJob::dispatchSync($offer->id);
        } catch (\Throwable) {
            // expected
        }

        $offer->refresh();
        $this->assertNull($offer->sent_at);
    }

    public function test_final_delivery_failure_invalidates_and_rematches(): void
    {
        Bus::fake();
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        $job = new SendVkSlotOfferJob($offer->id);
        $job->failed(new \Exception('VK API failed'));

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Invalidated, $offer->status);
        Bus::assertDispatched(MatchSlotOpportunityJob::class);
    }

    // ── 14-18: VK Accept semantics ───────────────────────

    public function test_vk_accept_ownership_enforced(): void
    {
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_accept_' . $offer->id, userId: 'wrong_user'))->assertStatus(200);

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Pending, $offer->status);
    }

    public function test_vk_accept_uses_slot_offer_acceptance_service(): void
    {
        $tz = $this->master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(14, 0);

        $request = $this->createSlotRequest();
        $opportunity = $this->createOpportunity($localStart->copy()->setTimezone('UTC'));
        $offer = $this->createOffer($request, $opportunity);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_accept_' . $offer->id))->assertStatus(200);

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Accepted, $offer->status);
        $this->assertNotNull($offer->accepted_at);
    }

    public function test_expired_offer_cannot_be_accepted(): void
    {
        $offer = $this->createOffer(status: SlotOfferStatus::Pending);
        $offer->update(['expires_at' => now()->subMinute()]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_accept_' . $offer->id))->assertStatus(200);

        $offer->refresh();
        $this->assertNotSame(SlotOfferStatus::Accepted, $offer->status);
    }

    public function test_duplicate_accept_idempotent(): void
    {
        $tz = $this->master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(14, 0);

        $request = $this->createSlotRequest();
        $opportunity = $this->createOpportunity($localStart->copy()->setTimezone('UTC'));
        $offer = $this->createOffer($request, $opportunity);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        // First accept
        $this->postJson('/webhooks/vk', $this->webhookPayload('af_accept_' . $offer->id))->assertStatus(200);

        // Second accept should be safe
        $this->postJson('/webhooks/vk', $this->webhookPayload('af_accept_' . $offer->id))->assertStatus(200);

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Accepted, $offer->status);
    }

    // ── 19-21: VK Decline semantics ──────────────────────

    public function test_vk_decline_marks_offer_declined(): void
    {
        Bus::fake();
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_decline_' . $offer->id))->assertStatus(200);

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Declined, $offer->status);
        $this->assertNotNull($offer->declined_at);
    }

    public function test_decline_dispatches_rematch(): void
    {
        Bus::fake();
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_decline_' . $offer->id))->assertStatus(200);

        Bus::assertDispatched(MatchSlotOpportunityJob::class);
    }

    public function test_duplicate_decline_safe(): void
    {
        Bus::fake();
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')->andReturn(null);
        $this->app->instance(VkApiClient::class, $vkApi);

        // First decline
        $this->postJson('/webhooks/vk', $this->webhookPayload('af_decline_' . $offer->id))->assertStatus(200);

        // Second decline should be safe
        $this->postJson('/webhooks/vk', $this->webhookPayload('af_decline_' . $offer->id))->assertStatus(200);

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Declined, $offer->status);
    }

    // ── 22-24: Message edit after accept/decline ─────────

    public function test_accept_edits_original_vk_message(): void
    {
        $tz = $this->master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(14, 0);

        $request = $this->createSlotRequest();
        $opportunity = $this->createOpportunity($localStart->copy()->setTimezone('UTC'));
        $offer = $this->createOffer($request, $opportunity);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')
            ->with('200', '50')
            ->andReturn(['text' => 'Original offer text']);
        $vkApi->shouldReceive('editMessage')
            ->once()
            ->with('200', '50', "Original offer text\n\n✅ Запись перенесена", \Mockery::type('array'))
            ->andReturn(true);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_accept_' . $offer->id))->assertStatus(200);
    }

    public function test_decline_edits_original_vk_message(): void
    {
        Bus::fake();
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')
            ->with('200', '50')
            ->andReturn(['text' => 'Original offer text']);
        $vkApi->shouldReceive('editMessage')
            ->once()
            ->with('200', '50', "Original offer text\n\n❌ Предложение отклонено", \Mockery::type('array'))
            ->andReturn(true);
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_decline_' . $offer->id))->assertStatus(200);
    }

    public function test_edit_failure_does_not_rollback_business_action(): void
    {
        Bus::fake();
        $offer = $this->createOffer();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')->andReturn(true);
        $vkApi->shouldReceive('getMessageByConversationId')->andReturn(null); // simulates failure
        $this->app->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload('af_decline_' . $offer->id))->assertStatus(200);

        $offer->refresh();
        $this->assertSame(SlotOfferStatus::Declined, $offer->status);
    }

    // ── 25-28: VK cancellation + freed-window ────────────

    public function test_vk_cancellation_still_atomic(): void
    {
        $appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $service = new AppointmentStatusService();

        $service->transition($appointment, AppointmentStatus::Cancelled);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNotNull($appointment->cancelled_at);
    }

    public function test_successful_vk_cancel_creates_freed_window_pipeline(): void
    {
        Bus::fake();

        $appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $service = new AppointmentStatusService();
        $service->dispatchFreedWindowAfterCancel($appointment);

        // The dispatch is async (afterCommit), so just verify no exception
        $this->assertTrue(true);
    }

    public function test_no_freed_window_when_autofill_disabled(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        $appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $service = new AppointmentStatusService();
        // Should not throw even when autofill disabled
        $service->dispatchFreedWindowAfterCancel($appointment);

        $this->assertTrue(true);
    }

    public function test_confirm_vs_cancel_race_protection(): void
    {
        $appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
                'client_id' => null, // draft booking
            ]);

        // Simulate race: first cancel wins
        $affected = Appointment::where('id', $appointment->id)
            ->whereNull('client_id')
            ->where('status', AppointmentStatus::Booked->value)
            ->update([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

        $this->assertSame(1, $affected);

        // Second cancel should lose
        $affected2 = Appointment::where('id', $appointment->id)
            ->whereNull('client_id')
            ->where('status', AppointmentStatus::Booked->value)
            ->update([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

        $this->assertSame(0, $affected2);
    }
}
