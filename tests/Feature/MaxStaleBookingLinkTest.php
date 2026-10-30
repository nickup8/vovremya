<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Models\Appointment;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\MaxApiClient;
use App\Services\Notification\MasterNotificationService;
use App\Webhooks\MaxWebhookHandler;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Регрессии устаревших MAX-ссылок/кнопок бронирования:
 * повторное открытие старой ссылки не открывает оформление,
 * не создаёт/не продлевает черновик и не трогает чужой черновик B.
 */
class MaxStaleBookingLinkTest extends TestCase
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

    private function createDraftAppointment(array $overrides = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->masterService)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ], $overrides));
    }

    private function createReturningClient(string $maxId = 'max_user_123'): Client
    {
        return Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => $maxId,
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);
    }

    private function invokeHandleBookingStart(string $userId, string $startParam): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'handleBookingStart');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $startParam);
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

    private function invokeHandleBookingContact(
        string $userId,
        string $contactUserId,
        string $phone,
        string $firstName,
        string $lastName,
        string $appointmentId,
    ): void {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'handleBookingContact');
        $method->setAccessible(true);
        $method->invoke(
            $this->handler,
            $userId,
            $contactUserId,
            $phone,
            $firstName,
            $lastName,
            $appointmentId,
        );
    }

    private function invokeAcceptMaxConsent(string $userId, string $callbackId, string $flow): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'acceptMaxConsent');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $callbackId, $flow);
    }

    private function invokeRememberBookingDraft(string $userId, string $appointmentId): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'rememberBookingDraft');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $appointmentId);
    }

    private function invokeClearDraftIfMatches(string $userId, string $appointmentId): void
    {
        $method = new \ReflectionMethod(MaxWebhookHandler::class, 'clearDraftIfMatches');
        $method->setAccessible(true);
        $method->invoke($this->handler, $userId, $appointmentId);
    }

    /** @var list<string> */
    private array $capturedTexts = [];

    private function captureTexts(): void
    {
        $this->capturedTexts = [];

        $texts = &$this->capturedTexts;

        $this->maxApi->shouldReceive('sendMessage')
            ->andReturnUsing(function (string $userId, string $text, ?array $attachments = null) use (&$texts): void {
                $texts[] = $text;
            })
            ->zeroOrMoreTimes();

        $this->maxApi->shouldReceive('answerCallback')
            ->andReturnUsing(function (string $callbackId, string $notification = '') use (&$texts): bool {
                if ($notification !== '') {
                    $texts[] = $notification;
                }

                return true;
            })
            ->zeroOrMoreTimes();

        $this->maxApi->shouldReceive('answerCallbackWithMessage')
            ->andReturnUsing(function (string $callbackId, string $text, ?array $extra = null) use (&$texts): bool {
                $texts[] = $text;

                return true;
            })
            ->zeroOrMoreTimes();
    }

    // ─── 1. Старая ссылка на прошедшую запись ───

    public function test_past_appointment_link_refused_without_buttons_and_draft(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
        ]);

        $this->captureTexts();

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertCount(1, $this->capturedTexts);
        $this->assertSame(__('bot.errors.booking_expired'), $this->capturedTexts[0]);
        $this->assertStringNotContainsString('Детали записи', $this->capturedTexts[0]);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
    }

    // ─── 2. Отменённая запись — отказ без изменений ───

    public function test_cancelled_appointment_refused_without_side_effects(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
        ]);

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->client_id);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
        Event::assertNotDispatched(AppointmentCreated::class);
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
    }

    // ─── 2b. Просроченная незавершённая запись ───

    public function test_overdue_unfinished_appointment_refused(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
            'status' => AppointmentStatus::Booked,
        ]);

        $this->captureTexts();
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertNull($appointment->refresh()->client_id);
    }

    // ─── 3. Своя будущая уже оформленная запись ───

    public function test_own_future_confirmed_appointment_already_booked_message(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
            'client_id' => $client->id,
            'source' => AppointmentSource::Max,
        ]);

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertSame([__('bot.errors.booking_already_confirmed')], $this->capturedTexts);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));

        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertNull($appointment->client_confirmed_at);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
    }

    // ─── 4. Чужая будущая уже оформленная запись — без раскрытия данных ───

    public function test_foreign_future_confirmed_appointment_hides_details(): void
    {
        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_other_client',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);
        // Текущий пользователь — клиент этого мастера, но запись чужая.
        $this->createReturningClient();

        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
            'client_id' => $otherClient->id,
        ]);

        $this->captureTexts();
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertSame([__('bot.errors.appointment_not_found')], $this->capturedTexts);
        $this->assertStringNotContainsString('Стрижка', $this->capturedTexts[0]);
        $this->assertStringNotContainsString('Детали записи', $this->capturedTexts[0]);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertSame($otherClient->id, $appointment->refresh()->client_id);
        $this->assertNull($appointment->client_confirmed_at);
    }

    // ─── 5. Действующий черновик продолжает работать ───

    public function test_valid_future_draft_still_shows_confirm_cancel(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        $this->maxApi->shouldReceive('sendMessage')
            ->once()
            ->with(
                'max_user_123',
                Mockery::on(fn (string $text) => str_contains($text, 'Детали записи')),
                Mockery::on(fn (array $extra) => $this->hasConfirmCancelInExtra($extra, $appointment->id)),
            );

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertSame(
            (string) $appointment->id,
            (string) Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'),
        );
    }

    // ─── 6. Повторное открытие той же ссылки не продлевает TTL черновика ───

    public function test_reopening_same_link_does_not_extend_draft_ttl(): void
    {
        $this->createReturningClient();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        // Доменный срок записи (created_at vs cleanup-порог) действует
        // весь тест; проверяем только кэш-TTL.
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::parse('2026-06-02 14:00:00', 'UTC'),
        ]);

        $this->captureTexts();

        $draftKey = CacheKeys::MAX_BOOKING_DRAFT.'max_user_123';
        config()->set('booking.draft_ttl', 60);

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);
        $this->assertSame((string) $appointment->id, (string) Cache::get($draftKey));

        // Повторное открытие до окончания исходного TTL.
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);
        $this->assertSame((string) $appointment->id, (string) Cache::get($draftKey));

        // За исходным сроком жизни ключ исчезает — повторное открытие
        // не продлило TTL.
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertNull(Cache::get($draftKey));

        // Доменный срок записи всё ещё действующий: черновик можно
        // открыть заново (и снова без продления того же ключа).
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);
        $this->assertSame((string) $appointment->id, (string) Cache::get($draftKey));

        Carbon::setTestNow();
    }

    // ─── 7. Запись стала недоступной после показа кнопок — callback ───

    public function test_confirm_callback_refused_when_appointment_became_cancelled(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);

        // Между показом кнопок и нажатием запись отменена.
        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->captureTexts();
        Queue::fake();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_stale', (string) $appointment->id);

        $this->assertContains(__('bot.errors.appointment_not_found'), $this->capturedTexts);
        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNull($appointment->client_id);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertSame(0, AppointmentNotificationEvent::where('appointment_id', $appointment->id)->count());
    }

    public function test_confirm_callback_refused_when_appointment_became_past(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
        ]);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);

        $this->captureTexts();
        Queue::fake();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_past', (string) $appointment->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertNull($appointment->refresh()->client_id);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    // ─── 7b. Удалённая запись / отсутствующий кэш — отказ в чат ───

    public function test_confirm_callback_refused_when_appointment_deleted(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);
        $appointment->delete();

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_deleted', (string) $appointment->id);

        $this->assertSame([__('bot.errors.appointment_not_found')], $this->capturedTexts);
        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
        Event::assertNotDispatched(AppointmentCreated::class);
    }

    public function test_cancel_callback_refused_when_appointment_deleted(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);
        $appointment->delete();

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel_deleted', (string) $appointment->id);

        $this->assertSame([__('bot.errors.appointment_not_found')], $this->capturedTexts);
        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
    }

    public function test_confirm_callback_refused_when_draft_cache_missing(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        // Кэш черновика отсутствует (TTL истёк) — запись жива.
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_no_cache', (string) $appointment->id);

        $this->assertSame([__('bot.errors.appointment_not_found')], $this->capturedTexts);
        $appointment->refresh();
        $this->assertNull($appointment->client_id);
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertSame($client->id, $client->refresh()->id);
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
        Event::assertNotDispatched(AppointmentCreated::class);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    public function test_cancel_callback_refused_when_draft_cache_missing(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment();

        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel_no_cache', (string) $appointment->id);

        $this->assertSame([__('bot.errors.appointment_not_found')], $this->capturedTexts);
        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->client_id);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    // ─── 8. Контакт при устаревшей записи — отказ без мутаций ───

    public function test_booking_contact_refused_when_appointment_became_past(): void
    {
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
        ]);
        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);

        $this->captureTexts();
        Queue::fake();

        $this->invokeHandleBookingContact(
            'max_user_123',
            'max_user_123',
            '+79991234567',
            'Анна',
            'Иванова',
            (string) $appointment->id,
        );

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertNull($appointment->refresh()->client_id);
        $this->assertDatabaseMissing('clients', ['phone' => '+79991234567']);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
    }

    public function test_booking_contact_already_confirmed_own_future_appointment(): void
    {
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
            'client_id' => $client->id,
            'source' => AppointmentSource::Max,
        ]);
        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);

        $this->captureTexts();
        Queue::fake();

        $this->invokeHandleBookingContact(
            'max_user_123',
            'max_user_123',
            $client->phone,
            'Анна',
            'Иванова',
            (string) $appointment->id,
        );

        $this->assertSame([__('bot.errors.booking_already_confirmed')], $this->capturedTexts);
        $this->assertSame($client->id, $appointment->refresh()->client_id);
        $this->assertNull($appointment->client_confirmed_at);
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    // ─── 9. Отмена по старой кнопке при прошедшей записи — без изменений ───

    public function test_cancel_callback_refused_when_appointment_became_past(): void
    {
        $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
        ]);
        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);

        $this->captureTexts();

        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel_stale', (string) $appointment->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    // ─── 10. Старый сценарий A не очищает актуальный черновик B ───

    public function test_stale_link_a_does_not_clear_current_draft_b(): void
    {
        $this->createReturningClient();

        $staleA = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
        ]);
        $draftB = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->addDays(2)->setTime(15, 0),
        ]);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $draftB->id, 3600);

        $this->captureTexts();
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$staleA->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertSame(
            (string) $draftB->id,
            (string) Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'),
        );
    }

    public function test_stale_confirm_a_does_not_clear_current_draft_b(): void
    {
        $this->createReturningClient();

        $staleA = $this->createDraftAppointment([
            'start_time' => Carbon::yesterday()->setTime(14, 0),
        ]);
        $draftB = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->addDays(2)->setTime(15, 0),
        ]);

        // Черновик указывает на B; кнопка нажата по старой записи A —
        // сверка черновика отклоняет (draft !== A), B не трогается.
        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $draftB->id, 3600);

        $this->captureTexts();
        $this->invokeConfirmMaxBooking('max_user_123', 'cb_a', (string) $staleA->id);

        $this->assertContains(__('bot.errors.appointment_not_found'), $this->capturedTexts);
        $this->assertSame(
            (string) $draftB->id,
            (string) Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'),
        );
        $this->assertNull($staleA->refresh()->client_id);
        $this->assertNull($draftB->refresh()->client_id);
    }

    public function test_stale_cancel_a_does_not_clear_current_draft_b(): void
    {
        $this->createReturningClient();

        $staleA = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
        ]);
        $draftB = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->addDays(2)->setTime(15, 0),
        ]);

        // Черновик уже переключён на B; отмена по старой кнопке A
        // не находит черновик A и не трогает B.
        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $draftB->id, 3600);

        $this->captureTexts();
        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel_a', (string) $staleA->id);

        $this->assertContains(__('bot.errors.appointment_not_found'), $this->capturedTexts);
        $this->assertSame(
            (string) $draftB->id,
            (string) Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'),
        );
        $this->assertSame(AppointmentStatus::Booked, $staleA->refresh()->status);
    }

    // ─── 11. Просроченный created_at при живом cron-окне ───

    public function test_future_draft_past_cleanup_threshold_refused_without_cron(): void
    {
        $threshold = (int) config('booking.cleanup_draft_threshold');
        $this->createReturningClient();

        // Будущий визит, created_at старше порога, cron ещё не выполнялся.
        $appointment = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
            'created_at' => now()->subMinutes($threshold + 5),
        ]);

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeHandleBookingStart('max_user_123', 'book_'.$appointment->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertNull($appointment->refresh()->client_id);
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $notifySpy->shouldNotHaveReceived('sendToMaster');
        Event::assertNotDispatched(AppointmentCreated::class);
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointment->id,
        ]);
    }

    public function test_draft_within_cleanup_threshold_still_confirms(): void
    {
        $threshold = (int) config('booking.cleanup_draft_threshold');
        $client = $this->createReturningClient();
        $appointment = $this->createDraftAppointment([
            'created_at' => now()->subMinutes($threshold - 5),
        ]);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointment->id, 3600);

        $this->maxApi->shouldReceive('answerCallback')
            ->once()
            ->andReturn(true);

        Queue::fake();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_fresh', (string) $appointment->id);

        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertSame(AppointmentSource::Max, $appointment->source);
        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertDatabaseHas('appointment_notification_events', [
            'appointment_id' => $appointment->id,
            'event_type' => 'booking_confirmed',
        ]);
        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
    }

    public function test_cleanup_threshold_boundary_matches_cleanup_command(): void
    {
        $threshold = (int) config('booking.cleanup_draft_threshold');
        $this->createReturningClient();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        // Ровно на границе: created_at == now - threshold. Очистка
        // использует строгий `<`, поэтому запись не отменяется и
        // ссылка остаётся рабочей.
        $onBoundary = $this->createDraftAppointment([
            'start_time' => Carbon::parse('2026-06-02 14:00:00', 'UTC'),
            'created_at' => Carbon::now()->subMinutes($threshold),
        ]);

        // На секунду старше границы: очистка отменяет — ссылка отказывает.
        $pastBoundary = $this->createDraftAppointment([
            'start_time' => Carbon::parse('2026-06-02 15:00:00', 'UTC'),
            'created_at' => Carbon::now()->subMinutes($threshold)->subSecond(),
        ]);

        $this->captureTexts();
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$onBoundary->id);
        $this->assertStringContainsString('Детали записи', $this->capturedTexts[0]);

        $this->capturedTexts = [];
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$pastBoundary->id);
        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertSame(AppointmentStatus::Booked, $onBoundary->fresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $pastBoundary->fresh()->status);

        Carbon::setTestNow();
    }

    /**
     * Замораживает время, создаёт просроченный черновик A (кнопки уже
     * показаны, кэш указывает на A) и свежий черновик B. Возвращает
     * время, когда порог A уже истёк, а B ещё действителен; cron не
     * запускался.
     */
    private function arrangeExpiredDraftAWithLiveDraftB(): array
    {
        $threshold = (int) config('booking.cleanup_draft_threshold');

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        $appointmentA = $this->createDraftAppointment([
            'start_time' => Carbon::parse('2026-06-02 14:00:00', 'UTC'),
            'created_at' => Carbon::now()->subMinutes($threshold - 1),
        ]);
        $draftB = $this->createDraftAppointment([
            'start_time' => Carbon::parse('2026-06-02 16:00:00', 'UTC'),
        ]);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $appointmentA->id, 7200);

        // Показ кнопок уже состоялся; порог истёк, cron не отрабатывал.
        Carbon::setTestNow(Carbon::now()->addMinutes(6));

        return [$appointmentA, $draftB];
    }

    private function assertDraftAExpiredUnchangedAndBSafe(Appointment $appointmentA, Appointment $draftB): void
    {
        $appointmentA->refresh();
        $this->assertSame(AppointmentStatus::Booked, $appointmentA->status);
        $this->assertNull($appointmentA->client_id);
        $this->assertNull($appointmentA->cancelled_at);

        $draftB->refresh();
        $this->assertSame(AppointmentStatus::Booked, $draftB->status);
        $this->assertNull($draftB->client_id);

        $this->assertNull(Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'));
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $appointmentA->id,
        ]);
        $this->assertDatabaseMissing('appointment_notification_events', [
            'appointment_id' => $draftB->id,
        ]);
        Event::assertNotDispatched(AppointmentCreated::class);
    }

    public function test_confirm_denied_after_draft_age_expiry(): void
    {
        $this->createReturningClient();
        [$appointmentA, $draftB] = $this->arrangeExpiredDraftAWithLiveDraftB();

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeConfirmMaxBooking('max_user_123', 'cb_expired', (string) $appointmentA->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertDraftAExpiredUnchangedAndBSafe($appointmentA, $draftB);
        $notifySpy->shouldNotHaveReceived('sendToMaster');

        Carbon::setTestNow();
    }

    public function test_cancel_denied_after_draft_age_expiry(): void
    {
        $this->createReturningClient();
        [$appointmentA, $draftB] = $this->arrangeExpiredDraftAWithLiveDraftB();

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeCancelMaxBooking('max_user_123', 'cb_cancel_expired', (string) $appointmentA->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertDraftAExpiredUnchangedAndBSafe($appointmentA, $draftB);
        $notifySpy->shouldNotHaveReceived('sendToMaster');

        Carbon::setTestNow();
    }

    public function test_contact_denied_after_draft_age_expiry(): void
    {
        $this->createReturningClient();
        [$appointmentA, $draftB] = $this->arrangeExpiredDraftAWithLiveDraftB();

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeHandleBookingContact(
            'max_user_123',
            'max_user_123',
            '+79991234567',
            'Анна',
            'Иванова',
            (string) $appointmentA->id,
        );

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertDraftAExpiredUnchangedAndBSafe($appointmentA, $draftB);
        $this->assertDatabaseMissing('clients', ['phone' => '+79991234567']);
        $notifySpy->shouldNotHaveReceived('sendToMaster');

        Carbon::setTestNow();
    }

    public function test_consent_denied_after_draft_age_expiry(): void
    {
        [$appointmentA, $draftB] = $this->arrangeExpiredDraftAWithLiveDraftB();

        // Клиент со старой версией PDN: сохранение согласия при
        // истёкшем черновике изменило бы карточку на v1.
        $client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_user_123',
            'pdn_consent_at' => Carbon::now()->subDay(),
            'pdn_consent_version' => 'v0',
        ]);
        $originalConsentAt = $client->pdn_consent_at;

        $notifySpy = Mockery::spy(MasterNotificationService::class);
        $this->app->instance(MasterNotificationService::class, $notifySpy);
        Queue::fake();
        $this->captureTexts();

        $this->invokeAcceptMaxConsent('max_user_123', 'cb_consent_expired', 'book');

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertDraftAExpiredUnchangedAndBSafe($appointmentA, $draftB);

        $client->refresh();
        $this->assertSame('v0', $client->pdn_consent_version);
        $this->assertEquals($originalConsentAt, $client->pdn_consent_at);
        $notifySpy->shouldNotHaveReceived('sendToMaster');

        Carbon::setTestNow();
    }

    public function test_overdue_draft_a_preserves_current_draft_b(): void
    {
        $threshold = (int) config('booking.cleanup_draft_threshold');
        $this->createReturningClient();

        $overdueA = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
            'created_at' => now()->subMinutes($threshold + 5),
        ]);
        $draftB = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->addDays(2)->setTime(15, 0),
        ]);

        Cache::put(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123', (string) $draftB->id, 3600);

        $this->captureTexts();
        $this->invokeHandleBookingStart('max_user_123', 'book_'.$overdueA->id);

        $this->assertSame([__('bot.errors.booking_expired')], $this->capturedTexts);
        $this->assertSame(
            (string) $draftB->id,
            (string) Cache::get(CacheKeys::MAX_BOOKING_DRAFT.'max_user_123'),
        );
        $this->assertNull($overdueA->refresh()->client_id);
        $this->assertNull($draftB->refresh()->client_id);
    }

    // ─── 12. Гонка условного очищения черновика ───

    public function test_stale_clear_does_not_delete_draft_replaced_in_parallel(): void
    {
        $this->createReturningClient();

        $staleA = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->setTime(14, 0),
        ]);
        $draftB = $this->createDraftAppointment([
            'start_time' => Carbon::tomorrow()->addDays(2)->setTime(15, 0),
        ]);

        $draftKey = CacheKeys::MAX_BOOKING_DRAFT.'max_user_123';
        $lockKey = CacheKeys::MAX_BOOKING_DRAFT_LOCK.'max_user_123';

        // Подменяем array-store на перехватывающий get: вставляем
        // параллельную попытку записи B между чтением A и удалением —
        // ровно то чередование, которое без лока стирало бы B.
        $store = new class extends ArrayStore
        {
            /** @var (callable(string, mixed): void)|null */
            public $onGet = null;

            public function get($key)
            {
                $value = parent::get($key);

                if ($this->onGet !== null) {
                    ($this->onGet)($key, $value);
                }

                return $value;
            }
        };
        Cache::swap(new Repository($store));

        // Ранее открыта ссылка A: кэш указывает на A.
        Cache::put($draftKey, (string) $staleA->id, 3600);

        $parallelResults = [];
        $staleId = (string) $staleA->id;
        $draftBId = (string) $draftB->id;
        $store->onGet = function (string $key, mixed $value) use ($draftKey, $lockKey, $staleId, &$parallelResults): void {
            if ($key !== $draftKey || (string) $value !== $staleId) {
                return;
            }

            // Параллельный запрос B: та же условная запись черновика.
            // Общий лок не пускает её внутрь критической секции A
            // (get уже выполнен, forget ещё нет).
            $parallelResults[] = Cache::lock($lockKey, 10)->get() ? 'entered' : 'blocked';
        };

        $this->invokeClearDraftIfMatches('max_user_123', $staleId);

        // Интерлюдия невозможна: мутация B ждёт общий лок и не стирается.
        $this->assertSame(['blocked'], $parallelResults);
        $this->assertNull(Cache::get($draftKey));

        // После освобождения лока параллельный запрос дописывает B —
        // и актуальный черновик B сохраняется.
        $this->invokeRememberBookingDraft('max_user_123', $draftBId);
        $this->assertSame($draftBId, (string) Cache::get($draftKey));
    }

    // ─── Helpers ───

    private function hasConfirmCancelInExtra(array $extra, string $appointmentId): bool
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
}
