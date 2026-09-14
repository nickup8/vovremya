<?php

namespace App\Webhooks;

use App\Enums\SlotOfferStatus;
use App\Jobs\MatchSlotOpportunityJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\SlotOffer;
use App\Services\AppointmentVisitConfirmationService;
use App\Services\SlotOfferAcceptanceService;
use App\Services\SlotOfferService;
use App\Services\VkApiClient;
use Illuminate\Support\Facades\Log;

class VkWebhookHandler
{
    public function __construct(
        private VkApiClient $vkApi,
        private AppointmentVisitConfirmationService $confirmService,
        private SlotOfferAcceptanceService $acceptanceService,
        private SlotOfferService $offerService,
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
        $conversationMessageId = (string) ($object['conversation_message_id'] ?? '');

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
            $this->handleConfirmVisit($command, $eventId, $userId, $peerId, $conversationMessageId);

            return;
        }

        if (str_starts_with($command, 'af_accept_')) {
            $this->handleAutofillAccept($command, $eventId, $userId, $peerId, $conversationMessageId);

            return;
        }

        if (str_starts_with($command, 'af_decline_')) {
            $this->handleAutofillDecline($command, $eventId, $userId, $peerId, $conversationMessageId);

            return;
        }

        $this->vkApi->answerMessageEvent($eventId, $userId, $peerId, 'Действие недоступно');
    }

    private function handleConfirmVisit(string $command, string $eventId, string $userId, string $peerId, string $conversationMessageId): void
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

        if (in_array($result['result'], ['ok', 'already'], true)) {
            $this->updateReminderMessage($peerId, $conversationMessageId);
        }

        $this->respond($eventId, $userId, $peerId, $text);
    }

    private function handleAutofillAccept(string $command, string $eventId, string $userId, string $peerId, string $conversationMessageId): void
    {
        $offerId = substr($command, 10);

        if ($offerId === '') {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        $offer = SlotOffer::with(['request.client'])->find($offerId);

        if ($offer === null) {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        if ($offer->request?->client?->vk_id !== $userId) {
            Log::warning('[VK] autofill accept: ownership violation', [
                'user_id' => $userId,
                'offer_id' => $offerId,
            ]);
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        $result = $this->acceptanceService->acceptEarlier($offer);

        if ($result['success']) {
            Log::info('[VK] autofill accept success', [
                'user_id' => $userId,
                'offer_id' => $offerId,
            ]);
            $this->respond($eventId, $userId, $peerId, 'Готово, запись перенесена.');
            $this->editAutofillMessage($peerId, $conversationMessageId, '✅ Запись перенесена');
        } else {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');
        }
    }

    private function handleAutofillDecline(string $command, string $eventId, string $userId, string $peerId, string $conversationMessageId): void
    {
        $offerId = substr($command, 11);

        if ($offerId === '') {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        $offer = SlotOffer::with(['request.client'])->find($offerId);

        if ($offer === null) {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        if ($offer->request?->client?->vk_id !== $userId) {
            Log::warning('[VK] autofill decline: ownership violation', [
                'user_id' => $userId,
                'offer_id' => $offerId,
            ]);
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        try {
            $this->offerService->decline($offer);
        } catch (\Throwable $e) {
            Log::warning('[VK] autofill decline failed', [
                'offer_id' => $offerId,
                'error' => $e->getMessage(),
            ]);
            $this->respond($eventId, $userId, $peerId, 'Предложение уже недоступно.');

            return;
        }

        MatchSlotOpportunityJob::dispatch($offer->slot_opportunity_id);

        Log::info('[VK] autofill decline success', [
            'user_id' => $userId,
            'offer_id' => $offerId,
        ]);

        $this->respond($eventId, $userId, $peerId, 'Хорошо, это время не подойдёт. Продолжим искать.');
        $this->editAutofillMessage($peerId, $conversationMessageId, '❌ Предложение отклонено');
    }

    private function editAutofillMessage(string $peerId, string $conversationMessageId, string $marker): void
    {
        if ($peerId === '' || $conversationMessageId === '') {
            return;
        }

        $message = $this->vkApi->getMessageByConversationId($peerId, $conversationMessageId);

        if ($message === null) {
            return;
        }

        $originalText = (string) ($message['text'] ?? '');

        if ($originalText === '') {
            return;
        }

        if (str_contains($originalText, $marker)) {
            return;
        }

        $this->vkApi->editMessage($peerId, $conversationMessageId, $originalText . "\n\n" . $marker, [
            'one_time' => true,
            'inline' => true,
            'buttons' => [],
        ]);
    }

    private function updateReminderMessage(string $peerId, string $conversationMessageId): void
    {
        if ($peerId === '' || $conversationMessageId === '') {
            return;
        }

        $marker = "\n\n✅ Визит подтверждён";

        $message = $this->vkApi->getMessageByConversationId($peerId, $conversationMessageId);

        if ($message === null) {
            return;
        }

        $originalText = (string) ($message['text'] ?? '');

        if ($originalText === '') {
            return;
        }

        if (str_contains($originalText, '✅ Визит подтверждён')) {
            return;
        }

        $this->vkApi->editMessage($peerId, $conversationMessageId, $originalText . $marker, [
            'one_time' => true,
            'inline' => true,
            'buttons' => [],
        ]);
    }

    private function respond(string $eventId, string $userId, string $peerId, string $text): void
    {
        if (! $this->vkApi->answerMessageEvent($eventId, $userId, $peerId, $text)) {
            $this->vkApi->sendMessage($peerId, $text);
        }
    }
}
