<?php

namespace Tests\Feature;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendAppointmentReminderJobTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private MasterService $ms;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vk.bot_token' => 'test_vk_token',
            'services.telegram.bot_token' => 'test_tg_token',
        ]);

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->ms = MasterService::factory()->forMaster($this->master)->create();
    }

    private function createAppointment(array $clientAttrs = [], array $aptAttrs = []): array
    {
        $client = Client::factory()->create(array_merge([
            'user_id' => $this->master->id,
        ], $clientAttrs));

        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->ms)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked->value,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'reminder_24h_sent_at' => null,
                'reminder_24h_dispatched_at' => null,
                'reminder_24h_failed_at' => null,
                'reminder_final_sent_at' => null,
                'reminder_final_dispatched_at' => null,
                'reminder_final_failed_at' => null,
            ], $aptAttrs));

        return [$appointment, $client];
    }

    // ── 1. VK 24h reminder uses sendMessageWithKeyboard ──

    public function test_vk_24h_uses_keyboard(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '12345'],
            ['source' => AppointmentSource::Vk]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->withArgs(function (string $peerId, string $text, array $keyboard) {
                return $peerId === '12345'
                    && $keyboard['inline'] === true
                    && $keyboard['one_time'] === false;
            })
            ->andReturn('msg_1');

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_24h_sent_at);
    }

    // ── 2. VK keyboard payload is JSON-encoded object ──

    public function test_vk_keyboard_payload_is_json_object(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '999'],
            ['source' => AppointmentSource::Vk]
        );

        $capturedKeyboard = null;

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturnUsing(function (string $peerId, string $text, array $keyboard) use (&$capturedKeyboard) {
                $capturedKeyboard = $keyboard;

                return 'msg_1';
            });

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $this->assertNotNull($capturedKeyboard);
        $button = $capturedKeyboard['buttons'][0][0];
        $this->assertSame('callback', $button['action']['type']);
        $this->assertSame(__('bot.buttons.confirm_visit'), $button['action']['label']);
        $this->assertSame('positive', $button['color']);

        $decoded = json_decode($button['action']['payload'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('command', $decoded);
        $this->assertSame('cv_'.$appointment->id, $decoded['command']);
        // Must NOT be a plain string like "cv_<id>"
        $this->assertNotSame('cv_'.$appointment->id, $button['action']['payload']);
    }

    // ── 3. VK final reminder uses sendMessage (no keyboard) ──

    public function test_vk_final_uses_plain_send(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '12345'],
            ['source' => AppointmentSource::Vk]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->with('12345', \Mockery::type('string'))
            ->andReturn('msg_2');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
    }

    // ── 4. VK text uses master timezone ──

    public function test_vk_text_uses_master_timezone(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '100'],
            ['source' => AppointmentSource::Vk]
        );

        $capturedText = null;

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $peerId, string $text) use (&$capturedText) {
                $capturedText = $text;

                return 'msg_3';
            });

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $expectedTime = $appointment->start_time
            ->timezone($this->master->getTimezone())
            ->format('H:i');

        $this->assertStringContainsString($expectedTime, $capturedText);
    }

    // ── 5. VK API success → sent_at written ──

    public function test_vk_success_writes_sent_at(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn('msg_ok');

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
    }

    // ── 6. VK API returns null → exception, no sent_at, lock removed ──

    public function test_vk_null_response_throws_and_rolls_back(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        $lockKey = 'reminder_final_vk_'.$appointment->id;

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn(null);

        try {
            (new SendAppointmentReminderJob($appointment, 'final'))->handle();
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('VK API failed', $e->getMessage());
        }

        $appointment->refresh();
        $this->assertNull($appointment->reminder_final_sent_at);
        $this->assertFalse(Cache::has($lockKey));
    }

    // ── 7. source=vk + mixed provider → routes ONLY to VK ──

    public function test_vk_source_routes_only_to_vk_even_with_other_ids(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '777', 'telegram_id' => 'tg_123', 'max_id' => 'max_456'],
            ['source' => AppointmentSource::Vk]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn('msg_vk');

        // No TG or MAX send should be attempted (would fail without HTTP mock)
        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
    }

    // ── 8. source=admin + vk_id → no channel → terminal failed ──

    public function test_admin_source_with_vk_id_marks_terminal_failed(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '888'],
            ['source' => AppointmentSource::Admin]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $appointment->refresh();
        $this->assertNull($appointment->reminder_24h_sent_at);
        $this->assertNotNull($appointment->reminder_24h_failed_at);
    }

    // ── 9. Lock already exists → no send, no markAsSent ──

    public function test_existing_lock_prevents_send_and_mark(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        Cache::add('reminder_24h_vk_'.$appointment->id, true, now()->addHours(12));

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');
        $vkMock->shouldNotReceive('sendMessage');

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $appointment->refresh();
        $this->assertNull($appointment->reminder_24h_sent_at);
    }

    // ── 10. Command dispatches Job + sets dispatched_at ──

    public function test_command_dispatches_job_and_sets_dispatched_at(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '111', 'telegram_id' => null, 'max_id' => null],
            [
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::now()->addHours(24),
            ]
        );

        Queue::fake();

        $this->artisan('appointments:reminders');

        Queue::assertPushed(SendAppointmentReminderJob::class, function (SendAppointmentReminderJob $job) use ($appointment) {
            $ref = new \ReflectionProperty(SendAppointmentReminderJob::class, 'appointment');
            $refAppt = $ref->getValue($job);

            $refType = new \ReflectionProperty(SendAppointmentReminderJob::class, 'type');
            $type = $refType->getValue($job);

            return $refAppt->id === $appointment->id && $type === '24h';
        });

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_24h_dispatched_at);
        $this->assertNull($appointment->reminder_24h_sent_at);
    }

    // ── 11. Command sets dispatched_at but NOT sent_at ──

    public function test_command_sets_dispatched_at_not_sent_at(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '222', 'telegram_id' => null, 'max_id' => null],
            [
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::now()->addHours(24),
            ]
        );

        Queue::fake();

        $this->artisan('appointments:reminders');

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_24h_dispatched_at);
        $this->assertNull($appointment->reminder_24h_sent_at);
    }

    // ── 12. Existing TG reminder still works ──

    public function test_telegram_source_still_sends(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['telegram_id' => 'tg_user_1'],
            ['source' => AppointmentSource::Telegram]
        );

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
    }

    // ── 13. Duplicate scheduler/job → no duplicate send ──

    public function test_duplicate_job_does_not_double_send(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '333'],
            ['source' => AppointmentSource::Vk]
        );

        $callCount = 0;

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;

                return 'msg_dup';
            });

        // First job acquires lock and sends
        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        // Second job sees lock → no send
        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $this->assertSame(1, $callCount);
        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
    }

    // ── 14. Non-booked appointment → no send ──

    public function test_cancelled_appointment_skipped(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '444'],
            [
                'source' => AppointmentSource::Vk,
                'status' => AppointmentStatus::Cancelled->value,
            ]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $appointment->refresh();
        $this->assertNull($appointment->reminder_24h_sent_at);
    }

    // ── 15. VK source=null + vk_id → no routing → terminal failed ──

    public function test_null_source_with_vk_id_marks_terminal_failed(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '555', 'telegram_id' => null, 'max_id' => null],
            ['source' => null]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $appointment->refresh();
        $this->assertNull($appointment->reminder_24h_sent_at);
        $this->assertNotNull($appointment->reminder_24h_failed_at);
    }

    // ══════════════════════════════════════════════════════════
    // NEW TESTS — dispatch loop prevention
    // ══════════════════════════════════════════════════════════

    // ── 16. First scheduler run dispatches 1, second dispatches 0 ──

    public function test_second_scheduler_tick_dispatches_zero_additional_jobs(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '111', 'telegram_id' => null, 'max_id' => null],
            [
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::now()->addHours(24),
            ]
        );

        Queue::fake();

        // First tick: should dispatch exactly 1
        $this->artisan('appointments:reminders');
        Queue::assertPushed(SendAppointmentReminderJob::class, 1);

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_24h_dispatched_at);

        // Second tick: should dispatch 0
        Queue::fake();
        $this->artisan('appointments:reminders');
        Queue::assertNothingPushed();
    }

    // ── 17. Failed send → failed_at set, no redispatch ──

    public function test_failed_send_sets_failed_at_and_not_eligible(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn(null);

        try {
            (new SendAppointmentReminderJob($appointment, 'final'))->handle();
        } catch (\Exception $e) {
            // Expected: VK API failed
        }

        // Simulate what failed() does after retries exhausted
        $job = new SendAppointmentReminderJob($appointment, 'final');
        $job->failed(new \Exception('exhausted'));

        $appointment->refresh();
        $this->assertNull($appointment->reminder_final_sent_at);
        $this->assertNotNull($appointment->reminder_final_failed_at);

        // Command should NOT dispatch for this appointment
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'reminder_final_failed_at' => $appointment->reminder_final_failed_at,
            'reminder_final_sent_at' => null,
            'reminder_final_dispatched_at' => null,
        ]);

        Queue::fake();
        $this->artisan('appointments:reminders');
        Queue::assertNothingPushed();
    }

    // ── 18. Success → sent_at set, no redispatch ──

    public function test_success_sets_sent_at_and_not_eligible(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn('msg_ok');

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
        $this->assertNull($appointment->reminder_final_failed_at);

        Queue::fake();
        $this->artisan('appointments:reminders');
        Queue::assertNothingPushed();
    }

    // ── 19. No channel → terminal failed, no redispatch ──

    public function test_no_channel_marks_terminal_failed_no_redispatch(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['telegram_id' => null, 'max_id' => null, 'vk_id' => null],
            ['source' => null]
        );

        (new SendAppointmentReminderJob($appointment, '24h'))->handle();

        $appointment->refresh();
        $this->assertNull($appointment->reminder_24h_sent_at);
        $this->assertNotNull($appointment->reminder_24h_failed_at);

        Queue::fake();
        $this->artisan('appointments:reminders');
        Queue::assertNothingPushed();
    }

    // ── 20. Past final appointment → not dispatched ──

    public function test_past_final_appointment_not_dispatched(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '111', 'telegram_id' => null, 'max_id' => null],
            [
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::now()->subHours(1),
            ]
        );

        Queue::fake();
        $this->artisan('appointments:reminders');
        Queue::assertNothingPushed();
    }

    // ── 21. Atomic claim: second claim affected rows = 0 ──

    public function test_atomic_claim_second_attempt_returns_zero_affected_rows(): void
    {
        $now = Carbon::now();

        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '111', 'telegram_id' => null, 'max_id' => null],
            [
                'source' => AppointmentSource::Vk,
                'start_time' => $now->copy()->addHours(24),
            ]
        );

        // First claim
        $claimed1 = DB::table('appointments')
            ->where('id', $appointment->id)
            ->whereNull('reminder_24h_sent_at')
            ->whereNull('reminder_24h_dispatched_at')
            ->whereNull('reminder_24h_failed_at')
            ->update(['reminder_24h_dispatched_at' => now()]);

        $this->assertSame(1, $claimed1);

        // Second claim — should fail
        $claimed2 = DB::table('appointments')
            ->where('id', $appointment->id)
            ->whereNull('reminder_24h_sent_at')
            ->whereNull('reminder_24h_dispatched_at')
            ->whereNull('reminder_24h_failed_at')
            ->update(['reminder_24h_dispatched_at' => now()]);

        $this->assertSame(0, $claimed2);
    }

    // ── 22. Manual retry after terminal failure → sent_at, failed_at cleared ──

    public function test_manual_retry_after_failure_succeeds_clears_failed_at(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        // Simulate terminal failed state
        $appointment->update([
            'reminder_final_dispatched_at' => now(),
            'reminder_final_failed_at' => now(),
        ]);

        // Admin runs queue:retry → new job executes successfully
        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn('msg_retry');

        (new SendAppointmentReminderJob($appointment, 'final'))->handle();

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
        $this->assertNull($appointment->reminder_final_failed_at);
    }

    // ── 24. failed() does not overwrite sent_at ──

    public function test_failed_does_not_overwrite_sent_at(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        // Simulate: send succeeded, then failed() is called (shouldn't happen, but defensive)
        $appointment->update([
            'reminder_final_sent_at' => now(),
        ]);

        $job = new SendAppointmentReminderJob($appointment, 'final');
        $job->failed(new \Exception('late failure'));

        $appointment->refresh();
        $this->assertNotNull($appointment->reminder_final_sent_at);
        $this->assertNull($appointment->reminder_final_failed_at);
    }

    // ── 25. Existing dispatched_at from old serialized job ──

    public function test_failed_works_without_dispatched_at_from_old_job(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '500'],
            ['source' => AppointmentSource::Vk]
        );

        // Old serialized job has no dispatched_at set
        $this->assertNull($appointment->reminder_final_dispatched_at);

        $job = new SendAppointmentReminderJob($appointment, 'final');
        $job->failed(new \Exception('old job failure'));

        $appointment->refresh();
        $this->assertNull($appointment->reminder_final_sent_at);
        $this->assertNotNull($appointment->reminder_final_failed_at);
    }

    // ── 26. Final query excludes past appointments ──

    public function test_command_excludes_past_final_appointments(): void
    {
        [$appointment, $client] = $this->createAppointment(
            ['vk_id' => '111', 'telegram_id' => null, 'max_id' => null],
            [
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::now()->subHours(5),
            ]
        );

        // Appointment is past but still within the old query window (no lower bound)
        Queue::fake();
        $this->artisan('appointments:reminders');

        // Should NOT be dispatched because start_time is in the past
        Queue::assertNothingPushed();

        $appointment->refresh();
        $this->assertNull($appointment->reminder_final_dispatched_at);
    }
}
