<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\MaxApiClient;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Дефект 1: напоминание несёт устойчивую идентичность поколения
 * расписания (appointments.schedule_version): проверяется перед отправкой,
 * запись результата — условная по поколению. Изменение цены поколение не
 * двигает. Окно отправки дополнительно проверяется внутри job'а. Старые
 * сериализованные payload'ы без новых полей десериализуются безопасно.
 */
class ReminderScheduleGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

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

        // Фиксированное «сейчас»: окна отправки в job'е детерминированы.
        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'reminder_hours_before_final' => 3],
            'address' => 'ул. Тестовая, 1',
        ]);
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
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::parse('2026-11-11 10:00:00', 'Europe/Moscow'),
            ], $attrs));
    }

    private function reschedule(Appointment $appointment, Carbon $newStart): void
    {
        $result = app(BookingService::class)->rescheduleAppointment(
            $appointment,
            $newStart->format('Y-m-d'),
            $newStart->format('H:i'),
            ignoreWarnings: true,
            confirmOutsideHours: true,
        );

        $this->assertTrue($result['success'], json_encode($result));
    }

    /**
     * Вырезает свойства из сериализованного payload'а и пересчитывает
     * счётчик свойств в заголовке объекта — так собирается payload,
     * сериализованный до появления новых полей генерации.
     *
     * @param  array<int, string>  $patterns
     */
    private function stripFromSerialized(string $serialized, array $patterns): string
    {
        $removed = 0;

        foreach ($patterns as $pattern) {
            $legacy = preg_replace($pattern, '', $serialized, 1, $count);
            $this->assertIsString($legacy, 'payload должен пересобраться');
            $serialized = $legacy;
            $removed += $count;
        }

        $this->assertGreaterThan(0, $removed, 'тест должен действительно вырезать поля');

        $result = preg_replace_callback(
            '/^(O:\d+:"App\\\\Jobs\\\\SendAppointmentReminderJob":)(\d+)(:\{)/',
            static fn (array $m): string => $m[1].((int) $m[2] - $removed).$m[3],
            $serialized,
            1,
        );

        $this->assertIsString($result);

        return $result;
    }

    /**
     * Payload, сериализованный до появления новых полей генерации
     * (expectedStartTime / expectedScheduleVersion).
     */
    private function legacyUnserialize(SendAppointmentReminderJob $job): SendAppointmentReminderJob
    {
        $legacy = $this->stripFromSerialized(serialize($job), [
            '/s:17:"expectedStartTime";s:\d+:"[^"]*";/',
            '/s:23:"expectedScheduleVersion";(?:i:\d+|N);/',
        ]);

        $this->assertStringNotContainsString('expectedStartTime', $legacy);
        $this->assertStringNotContainsString('expectedScheduleVersion', $legacy);

        $restored = unserialize($legacy);
        $this->assertInstanceOf(SendAppointmentReminderJob::class, $restored);

        return $restored;
    }

    // ── Генерация поколения ──

    public function test_schedule_version_exists_and_bumps_on_reschedule(): void
    {
        $appointment = $this->appointment($this->client());

        $this->assertSame(0, (int) $appointment->schedule_version);

        $this->reschedule($appointment, Carbon::parse('2026-11-13 14:00:00', 'Europe/Moscow'));

        $this->assertSame(1, (int) $appointment->fresh()->schedule_version);
    }

    public function test_price_change_does_not_bump_schedule_version(): void
    {
        $appointment = $this->appointment($this->client());

        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$appointment->id}/status", [
            'price' => 2900,
        ]);
        $this->assertLessThan(400, $response->status());

        $fresh = $appointment->fresh();
        $this->assertSame(2900.0, (float) $fresh->price);
        $this->assertSame(
            0,
            (int) $fresh->schedule_version,
            'изменение цены не должно двигать поколение расписания',
        );
    }

    public function test_client_change_bumps_schedule_version(): void
    {
        $client = $this->client();
        $other = $this->client(['max_id' => 'max-2']);
        $appointment = $this->appointment($client);

        $appointment->update(['client_id' => $other->id]);

        $this->assertSame(1, (int) $appointment->fresh()->schedule_version);
    }

    // ── Старое расписание / старый получатель не отправляются ──

    public function test_old_final_reminder_does_not_send_after_move_to_next_week(): void
    {
        $appointment = $this->appointment($this->client(), [
            'start_time' => Carbon::parse('2026-11-10 10:00:00', 'Europe/Moscow'),
        ]);

        $job = new SendAppointmentReminderJob($appointment, 'final');

        // Визит перенесён на следующую неделю после постановки job'а.
        $this->reschedule($appointment, Carbon::parse('2026-11-17 10:00:00', 'Europe/Moscow'));

        $this->mock(MaxApiClient::class)->shouldNotReceive('sendMessage');
        $this->mock(VkApiClient::class)->shouldNotReceive('sendMessage');

        $job->handle();

        $fresh = $appointment->fresh();
        $this->assertNull($fresh->reminder_final_sent_at, 'старая job не должна отправить напоминание');
        $this->assertNull($fresh->reminder_final_failed_at, 'старая job не должна трогать поля новой версии');
    }

    public function test_a_to_b_to_a_old_job_is_stale_even_though_start_time_matches(): void
    {
        // Старт храним в UTC-виде: после A→B→A raw-значение в БД идентично
        // исходному, и снимок start_time в job'е совпадает со свежим —
        // ловушку ловит только генерация поколения.
        $start = Carbon::parse('2026-11-11 07:00:00', 'UTC');
        $appointment = $this->appointment($this->client(), ['start_time' => $start]);

        $job = new SendAppointmentReminderJob($appointment, 'final');

        // A → B → A: стена снова 10:00 MSK (07:00 UTC), поколение уже другое.
        $this->reschedule($appointment, Carbon::parse('2026-11-12 14:00:00', 'Europe/Moscow'));
        $this->reschedule($appointment, Carbon::parse('2026-11-11 10:00:00', 'Europe/Moscow'));

        $fresh = $appointment->fresh();
        $this->assertSame($start->getTimestamp(), $fresh->start_time->getTimestamp());

        $this->mock(MaxApiClient::class)->shouldNotReceive('sendMessage');
        $this->mock(VkApiClient::class)->shouldNotReceive('sendMessage');

        $job->handle();

        $this->assertNull($fresh->reminder_final_sent_at, 'старая job не должна отправить вернувшееся A');
        $this->assertGreaterThan(0, (int) $fresh->schedule_version);
    }

    public function test_old_job_does_not_send_to_a_new_recipient(): void
    {
        $clientA = $this->client(['max_id' => 'max-a']);
        $clientB = $this->client(['max_id' => 'max-b']);
        $appointment = $this->appointment($clientA);

        $job = new SendAppointmentReminderJob($appointment, 'final');

        // Получатель сменился после постановки job'а.
        $appointment->update(['client_id' => $clientB->id]);

        $this->mock(MaxApiClient::class)->shouldNotReceive('sendMessage');

        $job->handle();

        $this->assertNull($appointment->fresh()->reminder_final_sent_at);
    }

    // ── Условная запись результата по поколению ──

    public function test_late_failed_of_old_generation_does_not_mark_new_version_failed(): void
    {
        // Raw-значение в БД после A→B→A идентично исходному (см. A→B→A-тест):
        // только снимок start_time их различил бы, поколение различает надёжно.
        $appointment = $this->appointment($this->client(), [
            'start_time' => Carbon::parse('2026-11-11 07:00:00', 'UTC'),
        ]);

        $job = new SendAppointmentReminderJob($appointment, 'final');

        $this->reschedule($appointment, Carbon::parse('2026-11-12 14:00:00', 'Europe/Moscow'));
        $this->reschedule($appointment, Carbon::parse('2026-11-11 10:00:00', 'Europe/Moscow'));

        $job->failed(new \RuntimeException('exhausted'));

        $this->assertNull(
            $appointment->fresh()->reminder_final_failed_at,
            'поздний failed() старого поколения не должен пометить новую версию',
        );
    }

    public function test_mark_as_sent_is_conditional_on_generation(): void
    {
        $appointment = $this->appointment($this->client());
        $job = new SendAppointmentReminderJob($appointment, 'final');

        // Поколение ушло вперёд между выполнением и записью результата.
        $appointment->update(['schedule_version' => 5]);

        (new ReflectionMethod(SendAppointmentReminderJob::class, 'markAsSent'))
            ->invoke($job, $appointment->fresh());

        $this->assertNull($appointment->fresh()->reminder_final_sent_at);
    }

    public function test_mark_terminal_failed_is_conditional_on_generation(): void
    {
        $appointment = $this->appointment($this->client());
        $job = new SendAppointmentReminderJob($appointment, 'final');
        $appointment->update(['schedule_version' => 7]);

        (new ReflectionMethod(SendAppointmentReminderJob::class, 'markTerminalFailed'))
            ->invoke($job, $appointment->fresh(), 'No routable channel for reminder');

        $this->assertNull($appointment->fresh()->reminder_final_failed_at);
    }

    public function test_mark_as_sent_succeeds_for_current_generation(): void
    {
        $appointment = $this->appointment($this->client());
        $job = new SendAppointmentReminderJob($appointment, 'final');

        (new ReflectionMethod(SendAppointmentReminderJob::class, 'markAsSent'))
            ->invoke($job, $appointment->fresh());

        $this->assertNotNull($appointment->fresh()->reminder_final_sent_at);
        $this->assertNull($appointment->fresh()->reminder_final_failed_at);
    }

    // ── Ключи кэша содержат поколение ──

    public function test_cache_key_includes_schedule_version_so_old_lock_does_not_block_new_reminder(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, [
            'source' => AppointmentSource::Vk,
            'start_time' => Carbon::parse('2026-11-11 10:00:00', 'Europe/Moscow'),
        ]);

        // Напоминание уходило для поколения 0 — прежний ключ живёт 12 часов.
        $oldKey = 'reminder_final_vk_'.$appointment->id;
        Cache::add($oldKey, true, now()->addHours(12));
        Cache::add($oldKey.'_v0', true, now()->addHours(12));

        $this->reschedule($appointment, Carbon::parse('2026-11-11 15:00:00', 'Europe/Moscow'));

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')->once()->andReturn('msg-new');

        (new SendAppointmentReminderJob($appointment->fresh(), 'final'))->handle();

        $fresh = $appointment->fresh();
        $this->assertNotNull($fresh->reminder_final_sent_at, 'ключ старого поколения не должен блокировать новое напоминание');
        $this->assertTrue(Cache::has($oldKey), 'ключ старого поколения остаётся невредимым');
    }

    public function test_same_generation_duplicate_still_blocked_by_cache(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, ['source' => AppointmentSource::Vk]);

        $job = new SendAppointmentReminderJob($appointment, 'final');
        $version = (new ReflectionProperty(
            SendAppointmentReminderJob::class,
            'expectedScheduleVersion',
        ))->getValue($job);
        $this->assertNotNull($version, 'новый job должен нести поколение');
        Cache::add('reminder_final_vk_'.$appointment->id.'_v'.$version, true, now()->addHours(12));

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $job->handle();

        $this->assertNull($appointment->fresh()->reminder_final_sent_at);
    }

    // ── Окно отправки внутри job ──

    public function test_final_reminder_out_of_window_is_skipped_even_with_matching_generation(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        // Визит через 6 дней: поколение не менялось, но окно не наступило.
        $appointment = $this->appointment($client, [
            'source' => AppointmentSource::Vk,
            'start_time' => Carbon::parse('2026-11-16 10:00:00', 'Europe/Moscow'),
        ]);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $this->assertNull($appointment->fresh()->reminder_final_sent_at);
    }

    public function test_24h_reminder_many_days_away_is_skipped_even_with_matching_generation(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, [
            'source' => AppointmentSource::Vk,
            'start_time' => Carbon::parse('2026-11-15 10:00:00', 'Europe/Moscow'),
        ]);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $this->assertNull($appointment->fresh()->reminder_24h_sent_at);
    }

    public function test_in_window_reminder_with_matching_generation_still_sends(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, [
            'source' => AppointmentSource::Vk,
            'start_time' => Carbon::parse('2026-11-10 10:00:00', 'Europe/Moscow'),
        ]);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')->once()->andReturn('msg-ok');

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $this->assertNotNull($appointment->fresh()->reminder_final_sent_at);
    }

    // ── Совместимость старых payload ──

    public function test_deserialized_payload_without_new_fields_does_not_crash_failed(): void
    {
        $appointment = $this->appointment($this->client());

        $restored = $this->legacyUnserialize(
            new SendAppointmentReminderJob($appointment, 'final'),
        );

        // Без поколения и без снимка времени явная безопасная политика:
        // не писать терминальные поля — нельзя доказать актуальность.
        $restored->failed(new \RuntimeException('exhausted'));

        $this->assertNull($appointment->fresh()->reminder_final_failed_at);
    }

    public function test_deserialized_legacy_payload_handle_does_not_send(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, ['source' => AppointmentSource::Vk]);

        $restored = $this->legacyUnserialize(
            new SendAppointmentReminderJob($appointment, 'final'),
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $restored->handle();

        $this->assertNull($appointment->fresh()->reminder_final_sent_at);
    }

    public function test_mid_generation_payload_with_start_time_snapshot_still_works(): void
    {
        // Payload предыдущей ревизии job'а: есть только expectedStartTime.
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, [
            'source' => AppointmentSource::Vk,
            'start_time' => Carbon::parse('2026-11-10 10:00:00', 'Europe/Moscow'),
        ]);

        $job = new SendAppointmentReminderJob($appointment, 'final');
        $legacy = $this->stripFromSerialized(serialize($job), [
            '/s:23:"expectedScheduleVersion";(?:i:\d+|N);/',
        ]);
        $this->assertStringNotContainsString('expectedScheduleVersion', $legacy);

        /** @var SendAppointmentReminderJob $restored */
        $restored = unserialize($legacy);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')->once()->andReturn('msg-mid');

        $restored->handle();

        $this->assertNotNull($appointment->fresh()->reminder_final_sent_at);
    }

    public function test_mid_generation_payload_with_changed_start_time_does_not_send(): void
    {
        $client = $this->client(['vk_id' => '777', 'max_id' => null]);
        $appointment = $this->appointment($client, [
            'source' => AppointmentSource::Vk,
            'start_time' => Carbon::parse('2026-11-11 10:00:00', 'Europe/Moscow'),
        ]);

        $job = new SendAppointmentReminderJob($appointment, 'final');
        $legacy = $this->stripFromSerialized(serialize($job), [
            '/s:23:"expectedScheduleVersion";(?:i:\d+|N);/',
        ]);

        /** @var SendAppointmentReminderJob $restored */
        $restored = unserialize($legacy);

        $this->reschedule($appointment, Carbon::parse('2026-11-10 15:00:00', 'Europe/Moscow'));

        $this->mock(MaxApiClient::class)->shouldNotReceive('sendMessage');
        $this->mock(VkApiClient::class)->shouldNotReceive('sendMessage');

        $restored->handle();

        $this->assertNull($appointment->fresh()->reminder_final_sent_at);
    }
}
