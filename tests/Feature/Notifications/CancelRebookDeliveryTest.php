<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\RecurringAppointmentSeries;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use App\Services\Booking\RecurringAppointmentService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

/**
 * B04: повторная запись после отмены мастером — URL-кнопка
 * «Записаться снова» на виджет мастера в MAX/ВК. Внешние API подменены;
 * живых отправок нет.
 */
class CancelRebookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_PHONE = '79001234567';

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

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'phone' => self::MASTER_PHONE,
            // Видимость виджета (scope visibleInWidget): owner-мастеру
            // нужен флаг поставщика услуг.
            'is_service_provider' => true,
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
            'workspace_id' => $this->workspace->id,
            'name' => 'Анна Смирнова',
            'phone' => '79990001122',
        ], $attrs));
    }

    private function manualAppointment(Client $client): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'service_name' => 'Стрижка',
                'price' => 1500,
                'start_time' => Carbon::now('Europe/Moscow')->addDays(3)->setTime(10, 0),
            ]);
    }

    private function cancelByMaster(Appointment $appointment): AppointmentNotificationEvent
    {
        app(BookingService::class)->cancel($appointment, $this->master);

        return AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CancelledByMaster)
            ->firstOrFail();
    }

    private function runJob(
        AppointmentNotificationEvent $event,
        MaxApiClient $maxApi,
        VkApiClient $vkApi,
    ): void {
        (new SendAppointmentNotificationJob($event->getKey()))->handle(
            app(AppointmentChannelResolver::class),
            $maxApi,
            $vkApi,
        );
    }

    private function rebookUrl(): string
    {
        return URL::route('booking.widget', ['master' => $this->master->master_slug]);
    }

    /**
     * Серия из трёх визитов + отмена мастером → единственное событие
     * SeriesCancelled (без индивидуальных отмен).
     */
    private function cancelSeriesByMaster(Client $client): AppointmentNotificationEvent
    {
        $series = RecurringAppointmentSeries::create([
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'master_service_id' => $this->service->id,
            'start_date' => Carbon::now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00',
            'recurrence_type' => RecurrenceType::Weekly,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);
        collect(range(1, 3))->each(function (int $i) use ($client, $series) {
            Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withMasterService($this->service)
                ->create([
                    'status' => AppointmentStatus::Booked,
                    'source' => AppointmentSource::Admin,
                    'service_name' => 'Стрижка',
                    'price' => 1500,
                    'start_time' => Carbon::now('Europe/Moscow')->addWeeks($i)->setTime(10, 0),
                    'recurring_series_id' => $series->id,
                    'recurring_occurrence_date' => Carbon::now('Europe/Moscow')->addWeeks($i)->format('Y-m-d'),
                ]);
        });

        app(RecurringAppointmentService::class)->cancelWholeSeries($series, $this->master);

        $this->assertSame(
            1,
            AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)->count(),
            'отмена серии даёт ровно одно series-событие',
        );

        return AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)
            ->firstOrFail();
    }

    // ── MAX: одиночная отмена — link-кнопка, одна доставка, без восстановления ──

    public function test_cancelled_by_master_max_delivery_carries_rebook_link_and_delivers_once(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $capturedExtra = null;
        $capturedText = null;
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $chatId, string $text, ?array $extra = null) use (
                &$capturedExtra,
                &$capturedText,
            ): bool {
                $capturedExtra = $extra;
                $capturedText = $text;

                return $chatId === 'max-1';
            })
            ->andReturn('mid-rebook-1');

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob($event, $maxApi, $vkApi);
        // Повторный проход уже доставленного события не шлёт вторую копию.
        $this->runJob($event, $maxApi, $vkApi);

        $this->assertNotNull($capturedText);
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите «Записаться снова».', $capturedText);
        $this->assertStringContainsString('Телефон мастера: +7 900 123-45-67', $capturedText);

        // Снимок повторной записи: исходный мастер, URL и вариант без CTA.
        $this->assertSame($this->rebookUrl(), $event->payload['rebook_url'] ?? null);
        $this->assertSame(
            (string) $this->master->id,
            (string) ($event->payload['rebook_master_id'] ?? null),
            'исходный мастер сохранён в снимке события',
        );
        $this->assertNotEmpty($event->payload['text_no_rebook'] ?? '');
        $this->assertStringNotContainsString(
            'Записаться снова',
            (string) $event->payload['text_no_rebook'],
            'вариант без CTA согласован с вариантом в payload[text]',
        );
        $this->assertStringContainsString('«Записаться снова»', (string) $event->payload['text']);

        $this->assertIsArray($capturedExtra, 'кнопка «Записаться снова» прикладывается к MAX-отправке');
        $button = $capturedExtra['attachments'][0]['payload']['buttons'][0][0] ?? null;
        $this->assertSame('inline_keyboard', $capturedExtra['attachments'][0]['type'] ?? null);
        $this->assertIsArray($button);
        $this->assertSame('link', $button['type']);
        $this->assertSame('Записаться снова', $button['text']);
        $this->assertSame($this->rebookUrl(), $button['url'], 'кнопка ведёт на виджет мастера этой записи');

        $rows = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)->get();
        $this->assertCount(1, $rows, 'ровно одна строка доставки');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $rows->first()->status);

        $this->assertSame(
            AppointmentStatus::Cancelled,
            $appointment->fresh()->status,
            'доставка не восстанавливает отменённую запись',
        );
    }

    // ── ВК: отмена серии — open_link-кнопка на тот же виджет ──

    public function test_series_cancelled_vk_delivery_carries_open_link_button(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $event = $this->cancelSeriesByMaster($client);

        $capturedKeyboard = null;
        $capturedText = null;
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->withArgs(function (string $peerId, string $text, array $keyboard) use (
                &$capturedKeyboard,
                &$capturedText,
            ): bool {
                $capturedKeyboard = $keyboard;
                $capturedText = $text;

                return $peerId === '111';
            })
            ->andReturn('msg-rebook-1');
        $vkApi->shouldNotReceive('sendMessage');

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');

        $this->runJob($event, $maxApi, $vkApi);

        $this->assertNotNull($capturedText);
        $this->assertStringContainsString('Хотите записаться на другую дату? Нажмите «Записаться снова».', $capturedText);

        $this->assertIsArray($capturedKeyboard);
        $this->assertTrue($capturedKeyboard['inline']);
        $this->assertFalse($capturedKeyboard['one_time']);
        $action = $capturedKeyboard['buttons'][0][0]['action'] ?? null;
        $this->assertIsArray($action);
        $this->assertSame('open_link', $action['type']);
        $this->assertSame('Записаться снова', $action['label']);
        $this->assertSame($this->rebookUrl(), $action['link'], 'кнопка ведёт на виджет мастера этой записи');

        $rows = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $rows->first()->status);

        // Доставка не восстанавливает ни одну запись серии.
        $anchor = Appointment::findOrFail($event->appointment_id);
        $seriesVisits = Appointment::where('recurring_series_id', $anchor->recurring_series_id)->get();
        $this->assertCount(3, $seriesVisits);
        foreach ($seriesVisits as $visit) {
            $this->assertSame(AppointmentStatus::Cancelled, $visit->fresh()->status);
        }
    }

    // ── ВК: одиночная отмена — open_link-кнопка ──

    public function test_cancelled_by_master_vk_delivery_carries_open_link_button(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $capturedKeyboard = null;
        $capturedText = null;
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->withArgs(function (string $peerId, string $text, array $keyboard) use (
                &$capturedKeyboard,
                &$capturedText,
            ): bool {
                $capturedKeyboard = $keyboard;
                $capturedText = $text;

                return $peerId === '111';
            })
            ->andReturn('msg-rebook-single');

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');

        $this->runJob($event, $maxApi, $vkApi);

        $this->assertNotNull($capturedText);
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите «Записаться снова».', $capturedText);

        $action = $capturedKeyboard['buttons'][0][0]['action'] ?? null;
        $this->assertIsArray($capturedKeyboard);
        $this->assertIsArray($action);
        $this->assertSame('open_link', $action['type']);
        $this->assertSame('Записаться снова', $action['label']);
        $this->assertSame($this->rebookUrl(), $action['link'], 'кнопка ведёт на виджет мастера этой записи');
    }

    // ── MAX: отмена серии — link-кнопка на тот же виджет ──

    public function test_series_cancelled_max_delivery_carries_rebook_link(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $event = $this->cancelSeriesByMaster($client);

        $capturedExtra = null;
        $capturedText = null;
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $chatId, string $text, ?array $extra = null) use (
                &$capturedExtra,
                &$capturedText,
            ): bool {
                $capturedExtra = $extra;
                $capturedText = $text;

                return $chatId === 'max-1';
            })
            ->andReturn('mid-series-rebook');

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertNotNull($capturedText);
        $this->assertStringContainsString('Хотите записаться на другую дату? Нажмите «Записаться снова».', $capturedText);

        $this->assertIsArray($capturedExtra, 'кнопка «Записаться снова» прикладывается к MAX-отправке серии');
        $button = $capturedExtra['attachments'][0]['payload']['buttons'][0][0] ?? null;
        $this->assertIsArray($button);
        $this->assertSame('link', $button['type']);
        $this->assertSame('Записаться снова', $button['text']);
        $this->assertSame($this->rebookUrl(), $button['url'], 'кнопка ведёт на виджет мастера этой записи');
    }

    // ── Кнопка не зависит от наличия телефона мастера ──

    public function test_rebook_button_does_not_depend_on_master_phone(): void
    {
        $this->master->forceFill(['phone' => null])->save();

        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $capturedExtra = null;
        $capturedText = null;
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text, ?array $extra = null) use (
                &$capturedExtra,
                &$capturedText,
            ): string {
                $capturedExtra = $extra;
                $capturedText = $text;

                return 'mid-nophone-1';
            });

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertNotNull($capturedText);
        $this->assertStringNotContainsString('Телефон мастера:', $capturedText, 'пустой телефон — строка опущена');
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите «Записаться снова».', $capturedText);

        $this->assertIsArray($capturedExtra, 'кнопка прикладывается и без телефона мастера');
        $button = $capturedExtra['attachments'][0]['payload']['buttons'][0][0] ?? null;
        $this->assertIsArray($button);
        $this->assertSame($this->rebookUrl(), $button['url']);
    }

    // ── Ссылку сформировать нельзя: без ложной инструкции и без кнопки ──

    public function test_max_without_link_sends_plain_message_and_no_instruction(): void
    {
        $this->master->forceFill(['master_slug' => null])->save();

        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $text = (string) $event->payload['text'];
        $this->assertStringNotContainsString(
            'Записаться снова',
            $text,
            'без ссылки инструкция про отсутствующую кнопку не показывается',
        );
        $this->assertStringContainsString('Телефон мастера: +7 900 123-45-67', $text, 'телефон остаётся в данных');

        $maxApi = $this->mock(MaxApiClient::class);
        // with() со строго двумя матчерами: вызов с третьим аргументом
        // (attachments) не матчится и роняет тест — доказательство
        // прежнего 2-аргументного вызова без клавиатуры.
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->with('max-1', Mockery::type('string'))
            ->andReturn('mid-plain-1');

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    public function test_vk_without_link_sends_plain_message_without_keyboard(): void
    {
        $this->master->forceFill(['master_slug' => null])->save();

        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessage')
            ->once()
            ->with('111', Mockery::type('string'))
            ->andReturn('msg-plain-1');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob($event, $this->mock(MaxApiClient::class), $vkApi);

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    // ── Решение по снимку: slug изменён до доставки (MAX, одиночная) ──

    public function test_slug_changed_before_delivery_sends_saved_no_rebook_variant_without_button(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $this->assertNotEmpty($event->payload['rebook_url'] ?? null, 'событие записано со ссылкой');

        // До доставки slug изменён: сохранённый URL больше не ведёт на
        // виджет этого мастера — новый URL не подставляется.
        $this->master->update(['master_slug' => 'rebook-new-slug']);

        $captured = [];
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args) use (&$captured): bool {
                $captured = $args;

                return (string) $args[0] === 'max-1';
            })
            ->andReturn('mid-slug-changed');

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertCount(2, $captured, 'кнопка не прикладывается — прежний 2-аргументный вызов');
        $sentText = (string) $captured[1];
        $this->assertSame(
            (string) $event->payload['text_no_rebook'],
            $sentText,
            'отправлен сохранённый вариант текста без инструкции',
        );
        $this->assertStringNotContainsString('Записаться снова', $sentText);
        $this->assertStringNotContainsString('rebook-new-slug', $sentText, 'новый URL в текст не попадает');

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    // ── Решение по снимку: slug удалён до доставки (ВК, серия) ──

    public function test_series_slug_removed_before_delivery_sends_no_rebook_variant_without_keyboard(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $event = $this->cancelSeriesByMaster($client);

        $this->assertNotEmpty($event->payload['rebook_url'] ?? null, 'событие записано со ссылкой');

        $this->master->update(['master_slug' => null]);

        $captured = [];
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args) use (&$captured): bool {
                $captured = $args;

                return (string) $args[0] === '111';
            })
            ->andReturn('msg-slug-removed');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob($event, $this->mock(MaxApiClient::class), $vkApi);

        $this->assertCount(2, $captured, 'без клавиатуры — обычный вызов');
        $this->assertSame((string) $event->payload['text_no_rebook'], (string) $captured[1]);
        $this->assertStringNotContainsString('Записаться снова', (string) $captured[1]);

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    // ── Решение по снимку: виджет стал недоступен (ВК, одиночная) ──

    public function test_widget_unavailable_after_record_sends_no_rebook_variant(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $this->assertNotEmpty($event->payload['rebook_url'] ?? null, 'событие записано со ссылкой');

        // Slug жив, но виджет погас: показ страницы = 404 (visibleInWidget).
        $this->service->update(['is_active' => false]);

        $captured = [];
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args) use (&$captured): bool {
                $captured = $args;

                return (string) $args[0] === '111';
            })
            ->andReturn('msg-widget-down');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob($event, $this->mock(MaxApiClient::class), $vkApi);

        $this->assertCount(2, $captured, 'виджет недоступен — без клавиатуры');
        $this->assertSame((string) $event->payload['text_no_rebook'], (string) $captured[1]);
        $this->assertStringNotContainsString('Записаться снова', (string) $captured[1]);

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    // ── Решение по снимку: виджет погас после записи серии (MAX) ──

    public function test_series_widget_unavailable_after_record_sends_no_rebook_variant_on_max(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $event = $this->cancelSeriesByMaster($client);

        $this->assertNotEmpty($event->payload['rebook_url'] ?? null, 'событие записано со ссылкой');

        $this->service->update(['is_active' => false]);

        $captured = [];
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (...$args) use (&$captured): bool {
                $captured = $args;

                return (string) $args[0] === 'max-1';
            })
            ->andReturn('mid-series-widget-down');

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertCount(2, $captured, 'виджет недоступен — без attachments');
        $this->assertSame((string) $event->payload['text_no_rebook'], (string) $captured[1]);
        $this->assertStringNotContainsString('Записаться снова', (string) $captured[1]);

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    // ── Ссылка появилась только после записи: CTA не появляется ──

    public function test_widget_available_only_after_record_stays_without_cta(): void
    {
        // Виджет изначально недоступен (owner без флага поставщика).
        $this->master->update(['is_service_provider' => false]);

        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $this->assertArrayNotHasKey('rebook_url', $event->payload, 'ссылки в снимке не было');
        $this->assertStringNotContainsString(
            'Записаться снова',
            (string) $event->payload['text'],
            'сохранённый вариант без CTA',
        );

        // Ссылка появилась до доставки — снимок не переписывается.
        $this->master->update(['is_service_provider' => true]);

        $capturedText = null;
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->with('max-1', Mockery::type('string'))
            ->andReturnUsing(function (string $chatId, string $text) use (&$capturedText): string {
                $capturedText = $text;

                return 'mid-later-link';
            });

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertNotNull($capturedText);
        $this->assertSame(
            (string) $event->payload['text'],
            $capturedText,
            'вариант без CTA не превращается в новый текст',
        );
        $this->assertStringNotContainsString('Записаться снова', $capturedText);

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    // ── Старое событие без новых полей: доставка как есть ──

    public function test_old_format_event_without_rebook_fields_delivers_as_is(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $oldText = "❌ Мастер отменил вашу запись\n\nСтарый формат события без новых полей.";

        $event = AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::CancelledByMaster,
            'event_version' => (int) $appointment->fresh()->notification_version,
            'payload' => [
                'client_id' => $client->id,
                'text' => $oldText,
            ],
        ]);

        $capturedText = null;
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->with('max-1', Mockery::type('string'))
            ->andReturnUsing(function (string $chatId, string $text) use (&$capturedText): string {
                $capturedText = $text;

                return 'mid-old-format';
            });

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertSame($oldText, $capturedText, 'текст старого формата доставляется без изменений');

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    // ── Снимок данных: профиль после записи события не пересобирается ──

    public function test_delivery_uses_snapshot_text_and_stored_master_after_profile_change(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $event = $this->cancelByMaster($appointment);

        $originalName = $this->master->name;
        $this->assertNotEmpty($event->payload['rebook_url'] ?? null);

        // Профиль меняется ПОСЛЕ записи события: текст и данные — из снимка.
        $this->master->update([
            'name' => 'Новое Имя',
            'phone' => '79111111111',
            'address' => 'Новая улица, 9',
        ]);

        $capturedExtra = null;
        $capturedText = null;
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $chatId, string $text, ?array $extra = null) use (
                &$capturedExtra,
                &$capturedText,
            ): bool {
                $capturedExtra = $extra;
                $capturedText = $text;

                return $chatId === 'max-1';
            })
            ->andReturn('mid-snapshot');

        $this->runJob($event, $maxApi, $this->mock(VkApiClient::class));

        $this->assertNotNull($capturedText);
        $this->assertSame(
            (string) $event->payload['text'],
            $capturedText,
            'доставлен ровно снимочный текст с инструкцией',
        );
        $this->assertStringContainsString('Мастер: '.$originalName, $capturedText);
        $this->assertStringContainsString('+7 900 123-45-67', $capturedText, 'телефон из снимка');
        $this->assertStringNotContainsString('Новое Имя', $capturedText, 'текущий профиль не пересобирается');
        $this->assertStringNotContainsString('+7 911 111-11-11', $capturedText);
        $this->assertStringContainsString('«Записаться снова»', $capturedText);

        $this->assertIsArray($capturedExtra, 'кнопка активна: slug и виджет не менялись');
        $button = $capturedExtra['attachments'][0]['payload']['buttons'][0][0] ?? null;
        $this->assertIsArray($button);
        $this->assertSame($event->payload['rebook_url'], $button['url'], 'кнопка — сохранённый URL исходного мастера');
        $this->assertSame($this->rebookUrl(), $button['url']);

        $row = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }
}
