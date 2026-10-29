<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\RecurrenceType;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\RecurringAppointmentSeries;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\RecurringAppointmentService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Дефект 4 (P1): series-событие может отправить уже неверное расписание.
 *
 * eventIsStale() для series игнорирует notification_version якоря —
 * это спасает при изменении цены, но сохраняет устаревший снимок при
 * переносе или отмене первого визита. Обратная проблема: наличие
 * ЛЮБОГО SeriesCancelled гасит создание/обновление всей серии, даже
 * если отменена только часть будущих визитов.
 *
 * Контракт: актуальность по фактически затронутому расписанию —
 * не терять сведения об оставшихся визитах и не отправлять
 * отменённые даты как действующие.
 */
class SeriesScheduleFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
            'services.vk.bot_token' => 'test_vk_token',
            'services.vk.group_id' => '1',
            'services.vk.app_id' => '1',
        ]);

        Queue::fake();

        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Тестовая, 1',
        ]);
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->workspace->ensureSlug();
        $this->master->update(['workspace_id' => $this->workspace->id]);
        $this->service = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 1500,
            'duration_override' => 60,
        ]);
    }

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create(array_merge([
            'user_id' => $this->master->id,
            'max_id' => 'max-1',
        ], $attrs));
    }

    /**
     * Создать серию из N будущих визитов через публичный API сервиса.
     *
     * @param  array<int, string>  $dates
     */
    private function createSeries(Client $client, array $dates, string $startTime = '10:00'): RecurringAppointmentSeries
    {
        $series = app(RecurringAppointmentService::class)->createSeries(
            $this->master,
            $client->id,
            $this->service->id,
            $dates[0],
            $startTime,
            RecurrenceType::Weekly,
            1,
            null,
            null,
            count($dates),
            $dates,
        );

        $this->assertInstanceOf(RecurringAppointmentSeries::class, $series);

        return $series;
    }

    private function seriesEvent(RecurringAppointmentSeries $series, AppointmentNotificationEventType $type): AppointmentNotificationEvent
    {
        return AppointmentNotificationEvent::where('recurring_series_id', $series->id)
            ->where('event_type', $type)
            ->firstOrFail();
    }

    private function deliverText(AppointmentNotificationEvent $event): ?string
    {
        $sent = null;
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-1';
            });

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $resolver = app(AppointmentChannelResolver::class);
        $this->app->instance(AppointmentChannelResolver::class, $resolver);

        try {
            (new SendAppointmentNotificationJob($event->id))->handle(
                $resolver,
                $maxMock,
                $vkMock,
            );
        } catch (\Throwable) {
            // Терминальный failed допустим — важен факт отправки/неотправки.
        }

        return $sent;
    }

    // ── Перенос первого визита до доставки ──

    public function test_reschedule_first_visit_before_delivery_supersedes_stale_series_created(): void
    {
        $client = $this->client();
        $series = $this->createSeries($client, ['2026-11-17', '2026-11-24', '2026-12-01']);

        $event = $this->seriesEvent($series, AppointmentNotificationEventType::SeriesCreated);

        // Перенос первого визита через публичный HTTP-операцию.
        $anchor = Appointment::findOrFail($event->appointment_id);
        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$anchor->id}/status", [
            'start_time' => '2026-11-18 15:00:00',
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $text = $this->deliverText($event);

        $this->assertNull(
            $text,
            'SeriesCreated с датой перенесённого первого визита не должна доставляться',
        );

        // Старый job визита погашена версией; новое событие Rescheduled несёт новое время.
        $rescheduled = AppointmentNotificationEvent::where('appointment_id', $anchor->id)
            ->where('event_type', AppointmentNotificationEventType::Rescheduled)
            ->first();
        $this->assertNotNull($rescheduled, 'перенос должен породить Rescheduled');
        $rescheduledText = $this->deliverText($rescheduled);
        $this->assertNotNull($rescheduledText);
        $this->assertStringContainsString('15:00', $rescheduledText);
    }

    // ── Отмена первого визита до доставки ──

    public function test_cancel_first_visit_before_delivery_supersedes_stale_series_created(): void
    {
        $client = $this->client();
        $series = $this->createSeries($client, ['2026-11-17', '2026-11-24', '2026-12-01']);

        $event = $this->seriesEvent($series, AppointmentNotificationEventType::SeriesCreated);

        $anchor = Appointment::findOrFail($event->appointment_id);
        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$anchor->id}/status", [
            'status' => 'cancelled',
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $text = $this->deliverText($event);

        $this->assertNull(
            $text,
            'SeriesCreated с отменённым первым визитом не должна доставляться',
        );
    }

    // ── Отмена части будущих визитов до первого сообщения ──

    public function test_partial_series_cancel_keeps_remaining_visits_informed(): void
    {
        $client = $this->client();
        $series = $this->createSeries($client, [
            '2026-11-17',
            '2026-11-24',
            '2026-12-01',
            '2026-12-08',
            '2026-12-15',
        ]);

        $createdEvent = $this->seriesEvent($series, AppointmentNotificationEventType::SeriesCreated);

        // Отмена части будущих визитов: с 3-го и далее.
        $splitVisit = Appointment::where('recurring_series_id', $series->id)
            ->where('recurring_occurrence_date', '2026-12-01')
            ->firstOrFail();

        app(RecurringAppointmentService::class)->cancelThisAndFuture($series, $splitVisit, $this->master);

        // SeriesCreated с прежним счётчиком 5 не должна уходить.
        $createdText = $this->deliverText($createdEvent);
        $this->assertNull(
            $createdText,
            'SeriesCreated с устаревшим счётчиком 5 не должна доставляться после частичной отмены',
        );

        // SeriesCancelled несёт точный счётчик отменённых.
        $cancelEvent = $this->seriesEvent($series, AppointmentNotificationEventType::SeriesCancelled);
        $this->assertSame(3, (int) $cancelEvent->payload['affected_count']);
        $cancelText = $this->deliverText($cancelEvent);
        $this->assertNotNull($cancelText, 'SeriesCancelled должна доставиться');
        $this->assertStringContainsString('3', $cancelText, 'счётчик отменённых — 3');

        // Оставшиеся визиты не теряются: SeriesUpdated несёт счётчик 2.
        $updatedEvent = AppointmentNotificationEvent::where('recurring_series_id', $series->id)
            ->where('event_type', AppointmentNotificationEventType::SeriesUpdated)
            ->first();
        $this->assertNotNull(
            $updatedEvent,
            'после частичной отмены должен остаться SeriesUpdated по оставшимся визитам',
        );
        $this->assertSame(2, (int) $updatedEvent->payload['affected_count']);
        $updatedText = $this->deliverText($updatedEvent);
        $this->assertNotNull($updatedText);
        $this->assertStringContainsString('2', $updatedText, 'счётчик оставшихся — 2');
    }

    // ── Изменение цены первого визита не гасит серию ──

    public function test_price_change_of_first_visit_does_not_kill_series_created(): void
    {
        $client = $this->client();
        $series = $this->createSeries($client, ['2026-11-17', '2026-11-24', '2026-12-01']);

        $event = $this->seriesEvent($series, AppointmentNotificationEventType::SeriesCreated);

        $anchor = Appointment::findOrFail($event->appointment_id);
        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$anchor->id}/status", [
            'price' => 4444,
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $text = $this->deliverText($event);

        $this->assertNotNull(
            $text,
            'изменение цены первого визита не должно гасить уведомление о серии',
        );
        $this->assertStringContainsString('серию визитов', $text);
        $this->assertStringContainsString('3', $text, 'счётчик визитов сохраняется');
        $this->assertStringContainsString('17.11.2026', $text, 'дата первого визита сохраняется');
    }

    // ── Разные получатели ──

    public function test_series_freshness_is_per_recipient(): void
    {
        $clientA = $this->client(['max_id' => 'max-a']);
        $clientB = $this->client(['max_id' => 'max-b']);

        $seriesA = $this->createSeries($clientA, ['2026-11-17', '2026-11-24']);
        $seriesB = $this->createSeries($clientB, ['2026-11-18', '2026-11-25']);

        $eventA = $this->seriesEvent($seriesA, AppointmentNotificationEventType::SeriesCreated);
        $eventB = $this->seriesEvent($seriesB, AppointmentNotificationEventType::SeriesCreated);

        // Перенос первого визита серии A.
        $anchorA = Appointment::findOrFail($eventA->appointment_id);
        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$anchorA->id}/status", [
            'start_time' => '2026-11-19 15:00:00',
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        // Серия A погашена, серия B продолжает быть актуальной.
        $textA = $this->deliverText($eventA);
        $this->assertNull($textA, 'погашенная серия A не должна доставляться');

        $textB = $this->deliverText($eventB);
        $this->assertNotNull($textB, 'нетронутая серия B должна доставиться');
        $this->assertStringContainsString('18.11.2026', $textB);
        $this->assertStringContainsString('2', $textB);
    }
}
