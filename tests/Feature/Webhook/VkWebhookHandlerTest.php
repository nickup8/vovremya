<?php

namespace Tests\Feature\Webhook;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VkWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.vk.secret' => 'test_vk_secret_abc',
            'services.vk.confirmation_token' => 'vk_confirm_12345',
            'services.vk.bot_token' => 'test_bot_token',
        ]);
    }

    private function webhookPayload(string $command, ?string $eventId = 'evt1', ?string $userId = null, ?string $peerId = '200'): array
    {
        return [
            'type' => 'message_event',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
            'object' => [
                'event_id' => $eventId,
                'user_id' => $userId ?? '999',
                'peer_id' => $peerId,
                'payload' => $command,
            ],
        ];
    }

    private function createBookedAppointment(?string $clientVkId = '999', string $status = 'booked'): array
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'vk_id' => $clientVkId,
        ]);
        $ms = MasterService::factory()->forMaster($master)->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->forClient($client)
            ->withMasterService($ms)
            ->create([
                'status' => $status,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'client_confirmed_at' => null,
            ]);

        return [$appointment, $client, $master];
    }

    private function mockVkApiSuccess(): \Mockery\MockInterface
    {
        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')->andReturn(true);

        return $mock;
    }

    public function test_valid_cv_confirms_booked_appointment(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();
        $this->mockVkApiSuccess();

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: $client->vk_id))
            ->assertStatus(200)
            ->assertSeeText('ok', false);

        $appointment->refresh();
        $this->assertNotNull($appointment->client_confirmed_at);
    }

    public function test_client_confirmed_at_is_set(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();
        $this->mockVkApiSuccess();

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: $client->vk_id));

        $appointment->refresh();
        $this->assertNotNull($appointment->client_confirmed_at);
    }

    public function test_ownership_uses_vk_id_and_master_id(): void
    {
        [$appointment] = $this->createBookedAppointment(clientVkId: '111');
        $this->mockVkApiSuccess();

        // Different vk_id (222) should not be able to confirm
        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: '222'));

        $appointment->refresh();
        $this->assertNull($appointment->client_confirmed_at);
    }

    public function test_wrong_vk_id_gets_appointment_not_found(): void
    {
        [$appointment] = $this->createBookedAppointment(clientVkId: '111');

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', '222', '200', __('bot.errors.appointment_not_found'))
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: '222'));
    }

    public function test_appointment_missing_returns_not_found(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000001';

        $vkApi = \Mockery::mock(VkApiClient::class);
        $vkApi->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', '999', '200', __('bot.errors.appointment_not_found'))
            ->andReturn(true);
        $this->instance(VkApiClient::class, $vkApi);

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$fakeUuid}", userId: '999'));
    }

    public function test_not_booked_returns_not_available(): void
    {
        [$appointment, $client] = $this->createBookedAppointment(status: 'cancelled');

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', $client->vk_id, '200', __('bot.visit_confirm.not_available'))
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: $client->vk_id));
    }

    public function test_already_confirmed_returns_already(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();
        $appointment->update(['client_confirmed_at' => now()]);

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', $client->vk_id, '200', __('bot.visit_confirm.already'))
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: $client->vk_id));
    }

    public function test_success_returns_client_thanks(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', $client->vk_id, '200', __('bot.visit_confirm.client_thanks'))
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: $client->vk_id));
    }

    public function test_answer_message_event_receives_correct_fields(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt_custom', $client->vk_id, '300', \Mockery::type('string'))
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload(
            "cv_{$appointment->id}",
            eventId: 'evt_custom',
            userId: $client->vk_id,
            peerId: '300',
        ));
    }

    public function test_answer_false_falls_back_to_send_message(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->andReturn(false);
        $mock->shouldReceive('sendMessage')
            ->once()
            ->with('200', __('bot.visit_confirm.client_thanks'))
            ->andReturn('123');

        $this->postJson('/webhooks/vk', $this->webhookPayload("cv_{$appointment->id}", userId: $client->vk_id));
    }

    public function test_string_payload_supported(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();
        $this->mockVkApiSuccess();

        $payload = [
            'type' => 'message_event',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
            'object' => [
                'event_id' => 'evt1',
                'user_id' => $client->vk_id,
                'peer_id' => '200',
                'payload' => "cv_{$appointment->id}",
            ],
        ];

        $this->postJson('/webhooks/vk', $payload)->assertStatus(200);

        $appointment->refresh();
        $this->assertNotNull($appointment->client_confirmed_at);
    }

    public function test_object_payload_supported(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();
        $this->mockVkApiSuccess();

        $payload = [
            'type' => 'message_event',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
            'object' => [
                'event_id' => 'evt1',
                'user_id' => $client->vk_id,
                'peer_id' => '200',
                'payload' => ['command' => "cv_{$appointment->id}"],
            ],
        ];

        $this->postJson('/webhooks/vk', $payload)->assertStatus(200);

        $appointment->refresh();
        $this->assertNotNull($appointment->client_confirmed_at);
    }

    public function test_unknown_command_does_not_mutate_data(): void
    {
        [$appointment, $client] = $this->createBookedAppointment();

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', $client->vk_id, '200', 'Действие недоступно')
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload('unknown_cmd', userId: $client->vk_id));

        $appointment->refresh();
        $this->assertNull($appointment->client_confirmed_at);
    }

    public function test_malformed_message_event_does_not_crash(): void
    {
        $payload = [
            'type' => 'message_event',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
            'object' => [],
        ];

        $this->postJson('/webhooks/vk', $payload)->assertStatus(200);
    }

    public function test_webhook_always_returns_ok(): void
    {
        // Even with a bad command, webhook returns "ok"
        $this->mockVkApiSuccess();

        $this->postJson('/webhooks/vk', $this->webhookPayload('cv_nonexistent', userId: '999'))
            ->assertStatus(200)
            ->assertSeeText('ok', false);
    }

    public function test_empty_appointment_id_after_cv_returns_not_found(): void
    {
        $this->mockVkApiSuccess();

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('answerMessageEvent')
            ->once()
            ->with('evt1', '999', '200', __('bot.errors.appointment_not_found'))
            ->andReturn(true);

        $this->postJson('/webhooks/vk', $this->webhookPayload('cv_'));
    }

    public function test_existing_confirmation_and_secret_tests_still_pass(): void
    {
        // confirmation flow
        $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
        ])->assertStatus(200)->assertSeeText('vk_confirm_12345', false);

        // wrong secret
        $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
            'secret' => 'wrong',
        ])->assertStatus(403);

        // missing secret
        $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
        ])->assertStatus(403);
    }
}
