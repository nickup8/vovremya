<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Дефект 1 (P1): сообщение о переносе собирается со старым временем.
 *
 * CalendarController::updateStatus() вызывает rescheduleAppointment,
 * который загружает ДРУГОЙ экземпляр через lockForUpdate и сохраняет
 * новое время в нём. Контроллер игнорирует appointment из результата
 * сервиса и продолжает составлять событие из своего прежнего $locked:
 * - new_start_time берётся из старого start_time;
 * - composer получает прежние данные;
 * - old_master_id берётся через getOriginal уже после сохранений.
 *
 * Снимок исходных время/мастер снимается ДО мутаций; после успешного
 * сервиса используется актуальная возвращённая запись с отношениями.
 */
class RescheduleMessageFreshnessTest extends TestCase
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

    private function appointment(Client $client, array $attrs = []): Appointment
    {
        // Старт задаётся в UTC: wall-clock «10:00 Europe/Moscow» = 07:00 UTC.
        // Фабрика сохраняет переданный Carbon как есть (timestamp без tz),
        // поэтому tz-артефакты фикстуры исключаем явным UTC.
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::parse('2026-11-12 07:00:00', 'UTC'),
                'duration' => 60,
                'price' => 1500,
            ], $attrs));
    }

    private function patchStatus(Appointment $appointment, array $payload)
    {
        return $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", $payload);
    }

    /**
     * Красный: перенос 10:00 → 15:00 с одновременной сменой цены.
     * payload и текст должны нести СТАРОЕ и НОВОЕ время как разные
     * значения, а не оба как старое (текущий баг: new_start_time из $locked).
     */
    public function test_reschedule_message_carries_exact_old_and_new_times(): void
    {
        $appointment = $this->appointment($this->client());
        $expectedOld = $appointment->fresh()->start_time->toIso8601String();

        $response = $this->patchStatus($appointment, [
            'start_time' => '2026-11-13 15:00:00',
            'price' => 2900,
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::Rescheduled)
            ->firstOrFail();

        $fresh = $appointment->fresh();
        $expectedNew = $fresh->start_time->toIso8601String();

        $this->assertSame(
            $expectedOld,
            $event->payload['old_start_time'],
            'old_start_time — прежнее значение до переноса',
        );
        $this->assertSame(
            $expectedNew,
            $event->payload['new_start_time'],
            'new_start_time — НОВОЕ значение после переноса, а не старое из $locked',
        );
        $this->assertNotSame(
            $expectedOld,
            $expectedNew,
            'время реально должно измениться',
        );

        // Новое время — 15:00 в tz мастера (Europe/Moscow).
        $this->assertSame(
            '15:00',
            $fresh->start_time->timezone('Europe/Moscow')->format('H:i'),
        );

        $text = (string) $event->payload['text'];
        $oldFormatted = Carbon::parse($expectedOld)->timezone('Europe/Moscow');
        $newFormatted = Carbon::parse($expectedNew)->timezone('Europe/Moscow');
        $this->assertStringContainsString($oldFormatted->format('H:i'), $text, 'текст содержит прежнее время');
        $this->assertStringContainsString($newFormatted->format('H:i'), $text, 'текст содержит новое время');
        $this->assertStringContainsString($oldFormatted->format('d.m.Y'), $text);
        $this->assertStringContainsString($newFormatted->format('d.m.Y'), $text);
        $this->assertStringContainsString('2 900', $text, 'изменение цены должно попасть в сообщение');
    }

    /**
     * Красный: смена мастера при переносе.
     * old_master_id / new_master_id должны быть точными снимками,
     * снятыми ДО мутаций и после возврата сервиса.
     */
    public function test_reschedule_with_master_change_snapshots_both_masters(): void
    {
        $newMaster = User::factory()->master()->create([
            'settings' => ['timezone' => 'Asia/Yekaterinburg'],
            'address' => 'ул. Новая, 2',
        ]);
        $newWorkspace = Workspace::create([
            'name' => 'ws-new-'.$newMaster->id,
            'owner_id' => $newMaster->id,
        ]);
        $newWorkspace->ensureSlug();
        $newMaster->update(['workspace_id' => $this->workspace->id]);
        // Привязываем к тому же воркспейсу, чтобы reschedule прошёл проверку.
        $newMaster->forceFill(['workspace_id' => $this->workspace->id])->save();

        $client = $this->client();
        $appointment = $this->appointment($client);

        $response = $this->patchStatus($appointment, [
            'start_time' => '2026-11-13 15:00:00',
            'master_id' => $newMaster->id,
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::Rescheduled)
            ->firstOrFail();

        $this->assertSame(
            (string) $this->master->id,
            (string) $event->payload['old_master_id'],
            'old_master_id — прежний мастер, снятый до мутаций',
        );
        $this->assertSame(
            (string) $newMaster->id,
            (string) $event->payload['new_master_id'],
            'new_master_id — новый мастер из результата сервиса',
        );

        $text = (string) $event->payload['text'];
        $this->assertStringContainsString($this->master->name, $text, 'текст должен называть прежнего мастера');
        $this->assertStringContainsString($newMaster->name, $text, 'текст должен называть нового мастера');
    }

    /**
     * Красный: часовые пояса. Прежний мастер Europe/Moscow, новый Asia/Yekaterinburg.
     * composer должен форматировать новое время в часовом поясе НОВОГО мастера.
     */
    public function test_reschedule_formats_new_time_in_new_master_timezone(): void
    {
        $newMaster = User::factory()->master()->create([
            'settings' => ['timezone' => 'Asia/Yekaterinburg'],
        ]);
        $newMaster->forceFill(['workspace_id' => $this->workspace->id])->save();

        $client = $this->client();
        $appointment = $this->appointment($client);

        // 15:00 Мск = 17:00 Екб. Запись stored as UTC.
        $response = $this->patchStatus($appointment, [
            'start_time' => '2026-11-13 17:00:00', // интерпретируется в tz нового мастера
            'master_id' => $newMaster->id,
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::Rescheduled)
            ->firstOrFail();

        $newStart = Carbon::parse($event->payload['new_start_time']);
        // После фикса composer форматирует в tz нового мастера (Екб).
        $this->assertSame(
            '17:00',
            $newStart->timezone('Asia/Yekaterinburg')->format('H:i'),
            'новое время в tz нового мастера — 17:00',
        );

        $text = (string) $event->payload['text'];
        // Старое время 10:00 Мск = 12:00 Екб; composer показывает обе стороны в tz НОВОГО мастера.
        $this->assertStringContainsString('12:00', $text, 'прежнее время в tz нового мастера — 12:00 Екб');
        $this->assertStringContainsString('17:00', $text, 'новое время в tz нового мастера — 17:00 Екб');
    }

    /**
     * Job действительно доставляет точные времена из payload (не только запись в БД).
     */
    public function test_delivered_text_carries_exact_times_after_reschedule(): void
    {
        $client = $this->client();
        $appointment = $this->appointment($client);

        $response = $this->patchStatus($appointment, [
            'start_time' => '2026-11-13 15:00:00',
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::Rescheduled)
            ->firstOrFail();

        $maxMock = $this->mock(MaxApiClient::class);
        $sent = null;
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-1';
            });

        (new SendAppointmentNotificationJob($event->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $this->assertNotNull($sent, 'сообщение о переносе должно доставиться');
        $this->assertStringContainsString('10:00', $sent, 'доставленный текст содержит прежнее время 10:00');
        $this->assertStringContainsString('15:00', $sent, 'доставленный текст содержит новое время 15:00');
        $this->assertStringNotContainsString(
            '10:00',
            str_replace('Прежнее время: 12.11.2026 в 10:00', '', $sent),
            'новое время не должно совпадать со старым',
        );
    }
}
