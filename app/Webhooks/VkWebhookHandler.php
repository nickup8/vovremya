<?php

namespace App\Webhooks;

use App\Models\Appointment;
use App\Models\Client;
use App\Services\AppointmentVisitConfirmationService;
use App\Services\VkApiClient;
use Illuminate\Support\Facades\Log;

class VkWebhookHandler
{
    public function __construct(
        private VkApiClient $vkApi,
        private AppointmentVisitConfirmationService $confirmService,
    ) {}

    public function handle(array $payload): void
    {
        $type = $payload['type'] ?? '';

        if ($type !== 'message_event') {
            return;
        }

        $object = $payload['object'] ?? [];

        $eventId = (string) ($object['event_id'] ?? '');
        $userId = (string) ($object['user_id'] ?? '');
        $peerId = (string) ($object['peer_id'] ?? '');

        if ($eventId === '' || $userId === '' || $peerId === '') {
            Log::warning('[VK] message_event missing required fields');

            return;
        }

        $rawPayload = $object['payload'] ?? '';
        $command = '';

        if (is_string($rawPayload)) {
            $command = $rawPayload;
        } elseif (is_array($rawPayload) && isset($rawPayload['command'])) {
            $command = (string) $rawPayload['command'];
        }

        if (str_starts_with($command, 'cv_')) {
            $this->handleConfirmVisit($command, $eventId, $userId, $peerId);

            return;
        }

        $this->vkApi->answerMessageEvent($eventId, $userId, $peerId, 'Действие недоступно');
    }

    private function handleConfirmVisit(string $command, string $eventId, string $userId, string $peerId): void
    {
        $appointmentId = substr($command, 3);

        if ($appointmentId === '') {
            $this->respond($eventId, $userId, $peerId, __('bot.errors.appointment_not_found'));

            return;
        }

        $appointment = Appointment::with(['master', 'client'])->find($appointmentId);

        if (! $appointment) {
            $this->respond($eventId, $userId, $peerId, __('bot.errors.appointment_not_found'));

            return;
        }

        $client = Client::byVkId($userId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if (! $client) {
            Log::warning('[VK] confirm visit: ownership violation', [
                'user_id' => $userId,
                'appointment_id' => $appointmentId,
            ]);
            $this->respond($eventId, $userId, $peerId, __('bot.errors.appointment_not_found'));

            return;
        }

        $result = $this->confirmService->confirm($appointment, $client);

        $text = match ($result['result']) {
            'not_found' => __('bot.errors.appointment_not_found'),
            'not_available' => __('bot.visit_confirm.not_available'),
            'already' => __('bot.visit_confirm.already'),
            'ok' => __('bot.visit_confirm.client_thanks'),
        };

        if ($result['result'] === 'ok') {
            Log::info('[VK] confirm visit success', [
                'user_id' => $userId,
                'appointment_id' => $appointmentId,
            ]);
        }

        $this->respond($eventId, $userId, $peerId, $text);
    }

    private function respond(string $eventId, string $userId, string $peerId, string $text): void
    {
        if (! $this->vkApi->answerMessageEvent($eventId, $userId, $peerId, $text)) {
            $this->vkApi->sendMessage($peerId, $text);
        }
    }
}
