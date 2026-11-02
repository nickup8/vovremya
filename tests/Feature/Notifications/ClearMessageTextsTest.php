<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppointmentVisitConfirmationService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentNotificationComposer;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\Notification\MasterNotificationService;
use App\Support\BotText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * B04: понятные сообщения — составление текстов.
 *
 * Проверяются утверждённые тексты и условные строки (телефон/адрес/
 * инструкция), timezone мастера, единственный ₽, отсутствие
 * плейсхолдеров/разметки/висячих полей, снимок outbox и то, что кнопка
 * «Я приду» живёт только в первом напоминании. Живых отправок нет.
 */
class ClearMessageTextsTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_PHONE = '79001234567';

    private const MASTER_PHONE_DISPLAY = '+7 900 123-45-67';

    private User $master;

    private MasterService $service;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
        ]);

        Queue::fake();

        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        // Не-UTC пояс мастера; переход через полночь: 00:30 Екб —
        // это ещё 13.11 по UTC.
        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Asia/Yekaterinburg'],
            'address' => 'ул. Тестовая, 1',
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
            'max_id' => 'max-1',
        ], $attrs));
    }

    private function appointment(Client $client, array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'service_name' => 'Стрижка',
                // 14.11 00:30 Asia/Yekaterinburg = 13.11 19:30 UTC.
                'start_time' => Carbon::parse('2026-11-13 19:30:00', 'UTC'),
                'duration' => 60,
                'price' => 1500,
            ], $attrs));
    }

    /**
     * В тексте не должно оставаться плейсхолдеров, разметки и
     * висячих строк-полей («Метка: » без значения, пустых строк-полей).
     */
    private function assertCleanText(string $text, string $message): void
    {
        $this->assertDoesNotMatchRegularExpression('/:[a-z_][a-z0-9_]*/i', $text, "{$message}: остался плейсхолдер");
        $this->assertStringNotContainsString("\x00", $text, "{$message}: осталась метка пустого значения");
        $this->assertDoesNotMatchRegularExpression('/\*\*/', $text, "{$message}: markdown-маркер");
        $this->assertDoesNotMatchRegularExpression('/<[^>]+>/', $text, "{$message}: HTML-тег");
        $this->assertDoesNotMatchRegularExpression('/^[^\n]*:[ \t]+$/m', $text, "{$message}: пустое поле после двоеточия");
        $this->assertDoesNotMatchRegularExpression('/\n[ \t]+\n/', $text, "{$message}: строка из пробелов");
        $this->assertStringNotContainsString("\n\n\n", $text, "{$message}: лишняя пустая строка");
        $this->assertSame($text, rtrim($text), "{$message}: текст заканчивается пустой строкой");
    }

    // ── Оформленная запись: полный пакет, пояс мастера, единственный ₽ ──

    public function test_booking_confirmed_carries_master_contacts_in_master_timezone(): void
    {
        $appointment = $this->appointment($this->client());

        $text = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::BookingConfirmed,
            [],
        );

        $this->assertNotNull($text);
        $this->assertStringStartsWith('✅ Вы записаны на приём', $text);
        $this->assertStringContainsString('Мастер: '.$this->master->name, $text);
        $this->assertStringContainsString('Телефон мастера: '.self::MASTER_PHONE_DISPLAY, $text);
        $this->assertStringContainsString('Услуга: Стрижка', $text);
        $this->assertStringContainsString('Дата: 14.11.2026', $text, 'дата по поясу мастера, переход через полночь');
        $this->assertStringContainsString('Время: 00:30', $text, 'время по поясу мастера');
        $this->assertStringContainsString('Стоимость: 1 500 ₽', $text);
        $this->assertSame(1, substr_count($text, '₽'), 'ровно один знак ₽ на значение стоимости');
        $this->assertStringContainsString('Адрес: ул. Тестовая, 1', $text);
        $this->assertStringContainsString('Не сможете прийти? Отмените запись в личном кабинете.', $text);
        $this->assertStringContainsString('позвоните мастеру — номер указан выше', $text, 'инструкция A при наличии телефона');
        $this->assertStringNotContainsString('79990001122', $text, 'телефон клиента вместо телефона мастера не подставляется');
        $this->assertCleanText($text, 'booking_confirmed');
    }

    // ── Ручное создание без телефона и адреса: строки убраны, инструкция короткая ──

    public function test_created_by_master_without_phone_and_address_drops_lines(): void
    {
        $this->master->forceFill(['phone' => null, 'address' => null])->save();
        $appointment = $this->appointment($this->client());

        $text = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::CreatedByMaster,
            [],
        );

        $this->assertNotNull($text);
        $this->assertStringStartsWith('📅 Мастер записал вас на приём', $text);
        $this->assertStringNotContainsString('Телефон мастера:', $text, 'пустой телефон — вся строка убрана');
        $this->assertStringNotContainsString('Адрес:', $text, 'пустой адрес — вся строка убрана');
        $this->assertStringContainsString('Длительность: 60 мин', $text);
        $this->assertStringContainsString('Стоимость: 1 500 ₽', $text);
        $this->assertStringContainsString(
            'Не сможете прийти? Отмените запись в личном кабинете.',
            $text,
            'короткая инструкция A без телефона',
        );
        $this->assertStringNotContainsString('позвоните', $text, 'предложение позвонить без номера опущено');
        $this->assertCleanText($text, 'created_by_master без телефона');
    }

    // ── Перенос со сменой мастера: прежнее время в поясе прежнего мастера, ──
    //    новое — в поясе нового; контакты и адрес — нового мастера.

    public function test_reschedule_with_master_change_uses_each_master_timezone_and_new_contacts(): void
    {
        $oldMaster = $this->master;
        $newMaster = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'пр. Новый, 2',
            'phone' => '79119876543',
        ]);
        $newMaster->forceFill(['workspace_id' => $this->workspace->id])->save();

        $client = $this->client();
        $appointment = Appointment::factory()
            ->forMaster($newMaster)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'service_name' => 'Стрижка',
                'start_time' => Carbon::parse('2026-11-15 12:00:00', 'UTC'),
            ]);

        $text = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::Rescheduled,
            [
                'old_start_time' => Carbon::parse('2026-11-14 07:00:00', 'UTC')->toIso8601String(),
                'new_start_time' => Carbon::parse('2026-11-15 12:00:00', 'UTC')->toIso8601String(),
                'old_master_id' => $oldMaster->id,
                'new_master_id' => $newMaster->id,
                'service_name' => 'Стрижка',
            ],
        );

        $this->assertNotNull($text);
        $this->assertStringStartsWith('🔄 Ваша запись перенесена', $text);
        $this->assertStringContainsString('Был мастер: '.$oldMaster->name, $text);
        $this->assertStringContainsString('Теперь мастер: '.$newMaster->name, $text);
        // Прежнее время в поясе ПРЕЖНЕГО мастера (Екб): 07:00 UTC = 12:00.
        $this->assertStringContainsString('Было: 14.11.2026 в 12:00', $text);
        // Новое время в поясе НОВОГО мастера (Мск): 12:00 UTC = 15:00.
        $this->assertStringContainsString('Теперь: 15.11.2026 в 15:00', $text);
        $this->assertStringContainsString('Телефон мастера: +7 911 987-65-43', $text, 'телефон нового мастера');
        $this->assertStringContainsString('Адрес: пр. Новый, 2', $text, 'адрес нового мастера');
        $this->assertStringNotContainsString(self::MASTER_PHONE_DISPLAY, $text, 'телефон прежнего мастера не показывается');
        $this->assertStringNotContainsString('ул. Тестовая, 1', $text, 'адрес прежнего мастера не показывается');
        $this->assertStringContainsString('Если новое время вам не подходит, позвоните мастеру', $text);
        $this->assertCleanText($text, 'rescheduled смена мастера');
    }

    // ── Изменение полей: строки изменений, длительность в минутах, один ₽ ──

    public function test_fields_changed_lists_only_changed_fields_with_instruction(): void
    {
        $appointment = $this->appointment($this->client());

        $text = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::FieldsChanged,
            [
                'changes' => [
                    'service_name' => ['old' => 'Окрашивание', 'new' => 'Стрижка'],
                    'duration' => ['old' => 60, 'new' => 90],
                    'price' => ['old' => 1500, 'new' => 2900],
                ],
            ],
        );

        $this->assertNotNull($text);
        $this->assertStringStartsWith('✏️ Мастер изменил вашу запись', $text);
        $this->assertStringContainsString('Что изменилось:', $text);
        $this->assertStringContainsString('Услуга: Окрашивание → Стрижка', $text);
        $this->assertStringContainsString('Длительность: 60 мин → 90 мин', $text);
        $this->assertStringContainsString('Стоимость: 1 500 ₽ → 2 900 ₽', $text);
        $this->assertSame(2, substr_count($text, '₽'), 'по одному ₽ на каждое значение стоимости');
        $this->assertStringNotContainsString('₽ ₽', $text, 'двойной знак ₽ отсутствует');
        $this->assertStringContainsString('Если изменения вам не подходят, позвоните мастеру', $text);
        $this->assertCleanText($text, 'fields_changed');
    }

    // ── full_notice: одно согласованное сообщение без дублей вводного блока ──

    public function test_full_notice_keeps_single_intro_and_instruction(): void
    {
        $appointment = $this->appointment($this->client());
        $recorder = app(AppointmentNotificationRecorder::class);

        $version = $recorder->bumpVersion($appointment);
        $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::CreatedByMaster,
            [],
            $version,
        );

        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::FieldsChanged,
            ['changes' => ['price' => ['old' => 1500, 'new' => 2100]]],
            $version,
        );

        $this->assertNotNull($event);
        $text = (string) $event->payload['text'];

        $this->assertStringStartsWith('📅 Мастер записал вас на приём', $text, 'недоставленное создание несут полным текстом');
        $this->assertSame(1, substr_count($text, '📅 Мастер записал вас на приём'), 'вводный блок не дублируется');
        $this->assertSame(1, substr_count($text, 'Не сможете прийти?'), 'инструкция не дублируется');
        $this->assertStringContainsString('Стоимость: 1 500 ₽ → 2 100 ₽', $text, 'изменение цены в том же сообщении');
        $this->assertCleanText($text, 'full_notice');
    }

    // ── Отмена мастером: инструкция по ссылке, телефон в данных ──

    public function test_cancelled_by_master_instruction_depends_on_link_not_phone(): void
    {
        $appointment = $this->appointment($this->client());

        $withPhone = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::CancelledByMaster,
            [],
        );
        $this->assertNotNull($withPhone);
        $this->assertStringStartsWith('❌ Мастер отменил вашу запись', $withPhone);
        $this->assertStringContainsString(
            'Телефон мастера: '.self::MASTER_PHONE_DISPLAY,
            $withPhone,
            'телефон мастера остаётся в данных сообщения',
        );
        $this->assertStringContainsString('Хотите выбрать другое время? Нажмите „Записаться снова".', $withPhone);
        $this->assertStringNotContainsString('позвонит', $withPhone, 'предложение позвонить мастеру убрано');
        $this->assertStringNotContainsString('Ждём вас', $withPhone, 'универсальное «Ждём вас» к отказу не добавляется');
        $this->assertCleanText($withPhone, 'cancelled с телефоном');

        // Без телефона: строка телефона опущена, инструкция от него не зависит.
        $this->master->forceFill(['phone' => null])->save();
        $withoutPhone = app(AppointmentNotificationComposer::class)->compose(
            $appointment->fresh(),
            AppointmentNotificationEventType::CancelledByMaster,
            [],
        );
        $this->assertNotNull($withoutPhone);
        $this->assertStringNotContainsString('Телефон мастера:', $withoutPhone);
        $this->assertStringContainsString(
            'Хотите выбрать другое время? Нажмите „Записаться снова".',
            $withoutPhone,
            'инструкция и кнопка не зависят от наличия телефона',
        );
        $this->assertStringNotContainsString('позвонит', $withoutPhone);
        $this->assertCleanText($withoutPhone, 'cancelled без телефона');

        // Ссылку сформировать нельзя (нет slug): инструкция нажимать
        // отсутствующую кнопку не показывается, чужой мастер не подставляется.
        $this->master->forceFill(['phone' => self::MASTER_PHONE, 'master_slug' => null])->save();
        $noLink = app(AppointmentNotificationComposer::class)->compose(
            $appointment->fresh(),
            AppointmentNotificationEventType::CancelledByMaster,
            [],
        );
        $this->assertNotNull($noLink);
        $this->assertStringContainsString('Телефон мастера: '.self::MASTER_PHONE_DISPLAY, $noLink);
        $this->assertStringNotContainsString('Записаться снова', $noLink, 'без ссылки кнопка не анонсируется');
        $this->assertStringNotContainsString('позвонит', $noLink);
        $this->assertCleanText($noLink, 'cancelled без ссылки');
    }

    // ── Серии: кабинетные фразы и условное «Время приёма» ──

    public function test_series_messages_cabinet_phrases_and_conditional_time(): void
    {
        $appointment = $this->appointment($this->client());

        $created = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::SeriesCreated,
            ['affected_count' => 3, 'service_name' => 'Стрижка'],
        );
        $this->assertNotNull($created);
        $this->assertStringStartsWith('📅 Мастер записал вас на несколько приёмов', $created);
        $this->assertStringContainsString('Количество записей: 3', $created);
        $this->assertStringContainsString('Первый приём: 14.11.2026 в 00:30', $created);
        $this->assertStringContainsString('Даты всех приёмов можно посмотреть в личном кабинете.', $created);
        $this->assertCleanText($created, 'series_created');

        // Все визиты с одним временем — время показывается.
        $uniform = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::SeriesUpdated,
            ['affected_count' => 3, 'time' => '15:00', 'from_date' => '18.11.2026'],
        );
        $this->assertNotNull($uniform);
        $this->assertStringContainsString('Время приёма: 15:00', $uniform);
        $this->assertStringContainsString('Проверьте даты и время в личном кабинете.', $uniform);
        $this->assertCleanText($uniform, 'series_updated с общим временем');

        // Разное время визитов — общее время не доказуемо, строка опущена.
        $mixed = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::SeriesUpdated,
            ['affected_count' => 3, 'time' => null, 'from_date' => '18.11.2026'],
        );
        $this->assertNotNull($mixed);
        $this->assertStringNotContainsString('Время приёма:', $mixed, 'неподтверждённое общее время не утверждается');
        $this->assertCleanText($mixed, 'series_updated без общего времени');

        $cancelled = app(AppointmentNotificationComposer::class)->compose(
            $appointment,
            AppointmentNotificationEventType::SeriesCancelled,
            ['affected_count' => 2, 'from_date' => '18.11.2026'],
        );
        $this->assertNotNull($cancelled);
        $this->assertStringStartsWith('❌ Мастер отменил несколько ваших записей', $cancelled);
        $this->assertStringContainsString('Отменено записей: 2', $cancelled);
        $this->assertStringContainsString('Хотите записаться на другую дату? Нажмите „Записаться снова".', $cancelled);
        $this->assertStringNotContainsString('позвонит', $cancelled, 'предложение позвонить мастеру убрано');
        $this->assertCleanText($cancelled, 'series_cancelled с телефоном');

        // Нет ссылки (нет slug): инструкция о кнопке не показывается.
        $this->master->forceFill(['master_slug' => null])->save();
        $noLink = app(AppointmentNotificationComposer::class)->compose(
            $appointment->fresh(),
            AppointmentNotificationEventType::SeriesCancelled,
            ['affected_count' => 2, 'from_date' => '18.11.2026'],
        );
        $this->assertNotNull($noLink);
        $this->assertStringNotContainsString('Записаться снова', $noLink, 'без ссылки кнопка не анонсируется');
        $this->assertStringNotContainsString('позвонит', $noLink);
        $this->assertCleanText($noLink, 'series_cancelled без ссылки');
    }

    // ── Напоминания: точная дата, «Я приду» только в первом, без «завтра»/«часов» ──

    public function test_reminders_use_exact_date_and_confirm_button_only_in_24h(): void
    {
        $appointment = $this->appointment($this->client());

        $build = function (string $type) use ($appointment): string {
            $method = new \ReflectionMethod(SendAppointmentReminderJob::class, 'buildMessage');
            $method->setAccessible(true);

            return (string) $method->invoke(new SendAppointmentReminderJob($appointment, $type), $appointment, 'max');
        };

        $reminder24 = $build('24h');
        $this->assertStringStartsWith('⏰ Подтвердите, что придёте на приём', $reminder24);
        $this->assertStringContainsString('Нажмите «✅ Я приду»', $reminder24);
        $this->assertStringContainsString('Дата: 14.11.2026', $reminder24, 'точная дата вместо «завтра»');
        $this->assertStringContainsString('Время: 00:30', $reminder24);
        $this->assertStringContainsString('Телефон мастера: '.self::MASTER_PHONE_DISPLAY, $reminder24);
        $this->assertStringContainsString('Адрес: ул. Тестовая, 1', $reminder24);
        $this->assertStringContainsString('Не сможете прийти?', $reminder24);
        $this->assertStringNotContainsString('завтра', $reminder24);
        $this->assertCleanText($reminder24, 'reminder_24h');

        $final = $build('final');
        $this->assertStringStartsWith('🔔 Скоро ваш приём', $final);
        $this->assertStringContainsString('Дата: 14.11.2026', $final);
        $this->assertStringNotContainsString('Я приду', $final, 'кнопка подтверждения только в первом напоминании');
        $this->assertStringNotContainsString('осталось', $final, 'настройка :hours вместо точного времени убрана');
        $this->assertStringContainsString('Если опаздываете или не сможете прийти, позвоните мастеру', $final);
        $this->assertCleanText($final, 'reminder_final');

        // Подписи кнопок и payloads не меняются.
        $this->assertSame('✅ Я приду', __('bot.buttons.confirm_visit'));
        $this->assertSame('✅ Подтвердить запись', __('bot.buttons.confirm_booking'));
        $this->assertSame('Отменить', __('bot.buttons.cancel'));
    }

    // ── Напоминание без телефона мастера: без строки телефона и звонка ──

    public function test_reminder_without_master_phone_drops_phone_and_call_lines(): void
    {
        $this->master->forceFill(['phone' => null])->save();
        $appointment = $this->appointment($this->client());

        $method = new \ReflectionMethod(SendAppointmentReminderJob::class, 'buildMessage');
        $method->setAccessible(true);

        $reminder24 = (string) $method->invoke(
            new SendAppointmentReminderJob($appointment, '24h'),
            $appointment,
            'max',
        );

        $this->assertStringNotContainsString('Телефон мастера:', $reminder24);
        $this->assertStringNotContainsString('позвоните', $reminder24);
        $this->assertStringContainsString('Не сможете прийти? Отмените запись в личном кабинете.', $reminder24);
        $this->assertCleanText($reminder24, 'reminder_24h без телефона');
    }

    // ── Уведомления мастеру: имя клиента и локальное мастера время ──

    public function test_new_booking_alert_uses_client_name_and_master_local_time(): void
    {
        $this->master->forceFill(['max_id' => 'master-max', 'max_notifications' => true])->save();
        $appointment = $this->appointment($this->client());

        $captured = null;
        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $chatId, string $text) use (&$captured): bool {
                $captured = $text;

                return $chatId === 'master-max';
            });

        app(MasterNotificationService::class)->sendNewAppointmentAlert($appointment);

        $this->assertNotNull($captured);
        $this->assertStringStartsWith('📅 Новая запись', $captured);
        $this->assertStringContainsString('Клиент: Анна Смирнова', $captured);
        $this->assertStringContainsString('Телефон: 79990001122', $captured, 'телефон клиента, не мастера');
        $this->assertStringContainsString('Услуга: Стрижка', $captured);
        $this->assertStringContainsString('Дата: 14.11.2026', $captured, 'время мастера, переход через полночь');
        $this->assertStringContainsString('Время: 00:30', $captured);
        $this->assertStringNotContainsString(self::MASTER_PHONE_DISPLAY, $captured);
        $this->assertCleanText($captured, 'master.new_booking');
    }

    public function test_visit_confirmed_notifies_master_with_client_name(): void
    {
        $appointment = $this->appointment($this->client());
        $client = $appointment->client;

        $captured = null;
        $notifyMock = $this->mock(MasterNotificationService::class);
        $notifyMock->shouldReceive('sendToMaster')
            ->once()
            ->withArgs(function (User $master, string $text) use (&$captured): bool {
                $captured = $text;

                return $master->is($this->master);
            });

        $result = app(AppointmentVisitConfirmationService::class)->confirm($appointment, $client);

        $this->assertSame('ok', $result['result']);
        $this->assertNotNull($captured);
        $this->assertStringStartsWith('✅ Клиент подтвердил, что придёт', $captured);
        $this->assertStringContainsString('Клиент: Анна Смирнова', $captured);
        $this->assertStringContainsString('Дата: 14.11.2026', $captured);
        $this->assertStringContainsString('Время: 00:30', $captured);
        $this->assertCleanText($captured, 'master.visit_confirmed');
    }

    // ── MAX/Telegram-шаги записи: блоки данных без разметки ──

    public function test_booking_summary_and_details_render_without_markup(): void
    {
        $appointment = $this->appointment($this->client());
        $master = $this->master;

        $params = [
            'master' => $master->name,
            'master_phone' => BotText::masterPhone($master),
            'service' => $appointment->display_name,
            'date' => '14.11.2026',
            'time' => '00:30',
            'price' => BotText::price((float) $appointment->display_price),
            'address' => $master->address ?? '',
        ];

        $summary = BotText::render('bot.booking_summary', $params);
        $this->assertStringStartsWith('📋 Проверьте данные записи', $summary);
        $this->assertStringEndsWith('Чтобы завершить запись, нажмите «Подтвердить запись».', $summary);
        $this->assertStringContainsString('Стоимость: 1 500 ₽', $summary);
        $this->assertCleanText($summary, 'booking_summary');

        $details = BotText::render('bot.booking_details', $params);
        $this->assertStringStartsWith('📋 Детали записи', $details);
        $this->assertStringNotContainsString('нажмите «Подтвердить запись»', $details, 'инструкция о кнопке туда не приписывается');
        $this->assertCleanText($details, 'booking_details');
    }

    // ── Снимок outbox: смена профиля мастера не пересобирает текст ──

    public function test_outbox_snapshot_survives_master_profile_change(): void
    {
        $appointment = $this->appointment($this->client());
        $recorder = app(AppointmentNotificationRecorder::class);

        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::CreatedByMaster,
            [],
            $version,
        );

        $this->assertNotNull($event);
        $stored = (string) $event->payload['text'];
        $this->assertStringContainsString(self::MASTER_PHONE_DISPLAY, $stored);
        $this->assertStringContainsString('ул. Тестовая, 1', $stored);

        $this->master->forceFill([
            'name' => 'Другое Имя',
            'phone' => '79111111111',
            'address' => 'Новая улица, 9',
        ])->save();

        $event->refresh();
        $after = (string) $event->payload['text'];

        $this->assertSame($stored, $after, 'снимок текста не пересобирается после смены профиля');
        $this->assertStringNotContainsString('+7 911 111-11-11', $after);
        $this->assertStringNotContainsString('Новая улица, 9', $after);
    }
}
