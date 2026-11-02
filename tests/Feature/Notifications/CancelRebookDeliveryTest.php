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
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите „Записаться снова".', $capturedText);
        $this->assertStringContainsString('Телефон мастера: +7 900 123-45-67', $capturedText);

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
        $appointments = collect(range(1, 3))->map(function (int $i) use ($client, $series) {
            return Appointment::factory()
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

        $count = app(RecurringAppointmentService::class)->cancelWholeSeries($series, $this->master);
        $this->assertSame(3, $count);

        $this->assertSame(
            1,
            AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)->count(),
        );

        $event = AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)
            ->firstOrFail();

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
        $this->assertStringContainsString('Хотите записаться на другую дату? Нажмите „Записаться снова".', $capturedText);

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

        $this->assertSame(
            AppointmentStatus::Cancelled,
            $appointments->first()->fresh()->status,
            'доставка не восстанавливает отменённые записи серии',
        );
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
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите „Записаться снова".', $capturedText);

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

        $event = AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)
            ->firstOrFail();

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
        $this->assertStringContainsString('Хотите записаться на другую дату? Нажмите „Записаться снова".', $capturedText);

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
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите „Записаться снова".', $capturedText);

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
}
