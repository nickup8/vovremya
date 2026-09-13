<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Services\MaxApiClient;
use App\Webhooks\MaxWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class MaxBookingFastPathTest extends TestCase
{
    use RefreshDatabase;

    private MaxApiClient $maxApi;
    private MaxWebhookHandler $handler;
    private User $master;
    private Workspace $ws;
    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $this->maxApi);

        $this->handler = app(MaxWebhookHandler::class);

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->ws = Workspace::create([
            'name' => 'WS Test',
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->ws->id]);

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

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Стрижка',
            'base_price' => 1500,
            'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        config()->set('legal.version', 'v1');
        config()->set('booking.draft_ttl', 3600);
    }

    private function createDraftAppointment(): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ]);
    }

    private function createReturningClient(string $maxId = 'max_user_123', ?string $pdnVersion = 'v1'): Client
    {
        return Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => $maxId,
            'pdn_consent_at' => $pdnVersion ? now() : null,
            'pdn_consent_version' => $pdnVersion,
        ]);
    }

    private function invokeHandleBookingStart(string $userId, string $startParam): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'handleBookingStart');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $startParam);
    }

    private function invokeAcceptMaxConsent(string $userId, string $callbackId, string $flow): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'acceptMaxConsent');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $callbackId, $flow);
    }

    private function invokeConfirmMaxBooking(string $userId, string $callbackId, string $appointmentId): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'confirmMaxBooking');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $callbackId, $appointmentId);
    }

    private function invokeCancelMaxBooking(string $userId, string $callbackId, string $appointmentId): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'cancelMaxBooking');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $callbackId, $appointmentId);
    }

    // ─── Scenario 1: Existing same-master + global current PDN ───

    public function test_existing_client_with_current_pdn_shows_confirm_cancel(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'Детали записи')),
                Mockery::on(fn (array $extra) => $this->hasConfirmCancelButtons($extra, $appointment->id)),
            );

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertNull(Cache::get(CacheKeys::MAX_CONSENT_PENDING.'max_user_123'));
    }

    // ─── Scenario 2: Stale PDN → barrier → accept → confirm/cancel ───

    public function test_stale_pdn_shows_barrier_then_accept_saves_pdn_and_shows_confirm(): void
    {
        $client = $this->createReturningClient(pdnVersion: 'v0');
        $appointment = $this->createDraftAppointment();

        // Step 1: handleBookingStart should show PDN barrier
        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'согласие')),
                Mockery::any(),
            );

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        // Step 2: accept consent for returning client
        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->with(
                'cb_1',
                Mockery::on(fn (string $text) => str_contains($text, 'Согласие принято')),
            );

        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'Детали записи')),
                Mockery::any(),
            );

        $this->invokeAcceptMaxConsent('max_user_123', 'cb_1', 'book');

        $client->refresh();
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertEquals('v1', $client->pdn_consent_version);
    }

    // ─── Scenario 3: Global consent on another master, same-master stale ───

    public function test_global_consent_from_another_master_skips_pdn_barrier(): void
    {
        $otherMaster = User::factory()->master()->create();

        // Client under other master has current PDN
        Client::factory()->create([
            'user_id' => $otherMaster->id,
            'max_id' => 'max_user_123',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);

        // Client under target master has stale PDN
        $client = $this->createReturningClient(pdnVersion: 'v0');

        $appointment = $this->createDraftAppointment();

        // Should NOT show barrier, should show Confirm/Cancel directly
        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'Детали записи')),
                Mockery::any(),
            );

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);
    }

    // ─── Scenario 4: Same max_id only under another master ───

    public function test_max_id_only_under_other_master_requires_phone(): void
    {
        $otherMaster = User::factory()->master()->create();

        // Client exists under another master with current PDN
        Client::factory()->create([
            'user_id' => $otherMaster->id,
            'max_id' => 'max_user_123',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);

        // No client under target master for this max_id
        $appointment = $this->createDraftAppointment();

        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'поделитесь номером')),
                Mockery::on(fn (array $extra) => $this->hasRequestContactButton($extra)),
            );

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);
    }

    // ─── Scenario 5: Confirm returning client ───

    public function test_confirm_assigns_client_source_max_preserves_status(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', $appointment->id, 3600);

        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->andReturn(true);

        Event::fake([AppointmentCreated::class]);

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_confirm', (string) $appointment->id);

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
        $this->assertEquals(AppointmentSource::Max, $appointment->source);
        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);

        Event::assertDispatched(AppointmentCreated::class);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    // ─── Scenario 6: Double Confirm ───

    public function test_double_confirm_only_one_succeeds(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', $appointment->id, 3600);

        $this->maxApi->shouldReceive('answerCallbackWithMessage')->once()->andReturn(true);
        $this->maxApi->shouldReceive('answerCallback')->once();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb1', (string) $appointment->id);

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);

        // Second confirm should fail
        $this->invokeConfirmMaxBooking('max_user_123', 'cb2', (string) $appointment->id);

        // Still same client, no double side effects
        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
    }

    // ─── Scenario 7: Cancel before Confirm ───

    public function test_cancel_before_confirm_releases_slot(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', $appointment->id, 3600);

        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->with('cb_cancel', 'Запись отменена.')
            ->andReturn(true);

        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel', (string) $appointment->id);

        $appointment->refresh();
        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->client_id);
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    // ─── Scenario 8: Cancel then Confirm ───

    public function test_confirm_cannot_claim_cancelled_appointment(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', $appointment->id, 3600);

        // Cancel first
        $this->maxApi->shouldReceive('answerCallbackWithMessage')->once()->andReturn(true);
        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel', (string) $appointment->id);

        // Now try to confirm
        $this->maxApi->shouldReceive('answerCallback')->once();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_confirm', (string) $appointment->id);

        $appointment->refresh();
        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->client_id);
    }

    // ─── Scenario 9: Confirm then Cancel ───

    public function test_cancel_cannot_cancel_already_claimed_appointment(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', $appointment->id, 3600);

        // Confirm first
        $this->maxApi->shouldReceive('answerCallbackWithMessage')->once()->andReturn(true);
        $this->invokeConfirmMaxBooking('max_user_123', 'cb_confirm', (string) $appointment->id);

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);

        // Now try to cancel
        $this->maxApi->shouldReceive('answerCallback')->once();
        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel', (string) $appointment->id);

        // Should still be claimed
        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
        $this->assertEquals(AppointmentStatus::Booked, $appointment->status);
    }

    // ─── Scenario 10: Changed phone, same max_id ───

    public function test_fast_path_uses_existing_client_regardless_of_phone(): void
    {
        $client = $this->createReturningClient();
        $originalPhone = $client->phone;

        $appointment = $this->createDraftAppointment();

        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'Детали записи')),
                Mockery::any(),
            );

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $client->refresh();
        $this->assertEquals($originalPhone, $client->phone);
    }

    // ─── Scenario 11: PendingPayment draft ───

    public function test_confirm_preserves_pending_payment_status(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();
        $appointment->update(['status' => AppointmentStatus::PendingPayment]);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', $appointment->id, 3600);

        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->once()
            ->andReturn(true);

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_confirm', (string) $appointment->id);

        $appointment->refresh();
        $this->assertEquals($client->id, $appointment->client_id);
        $this->assertEquals(AppointmentSource::Max, $appointment->source);
        $this->assertEquals(AppointmentStatus::PendingPayment, $appointment->status);
    }

    // ─── Helpers ───

    private function hasConfirmCancelButtons(array $extra, string $appointmentId): bool
    {
        $buttons = $extra['attachments'][0]['payload']['buttons'] ?? [];

        $foundConfirm = false;
        $foundCancel = false;

        foreach ($buttons as $row) {
            foreach ($row as $button) {
                if (($button['payload'] ?? '') === 'max_confirm_'.$appointmentId) {
                    $foundConfirm = true;
                }
                if (($button['payload'] ?? '') === 'max_cancel_'.$appointmentId) {
                    $foundCancel = true;
                }
            }
        }

        return $foundConfirm && $foundCancel;
    }

    private function hasRequestContactButton(array $extra): bool
    {
        $buttons = $extra['attachments'][0]['payload']['buttons'] ?? [];

        foreach ($buttons as $row) {
            foreach ($row as $button) {
                if (($button['type'] ?? '') === 'request_contact') {
                    return true;
                }
            }
        }

        return false;
    }
}
