<?php

namespace App\Jobs;

use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Models\SlotOffer;
use App\Services\SlotOfferService;
use App\Services\VkApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendVkSlotOfferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public string $slotOfferId,
    ) {}

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(VkApiClient $vkApi, SlotOfferService $offerService): void
    {
        $offer = SlotOffer::with([
            'request.client',
            'request.master',
            'request.appointment',
            'opportunity.masterService',
        ])->find($this->slotOfferId);

        if ($offer === null) {
            Log::info('[AutoFill] SendVkSlotOfferJob: offer not found', [
                'offer_id' => $this->slotOfferId,
            ]);
            return;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            Log::info('[AutoFill] SendVkSlotOfferJob: offer not pending', [
                'offer_id' => $offer->id,
                'status' => $offer->status->value,
            ]);
            return;
        }

        if ($offer->sent_at !== null) {
            Log::info('[AutoFill] SendVkSlotOfferJob: already sent, skipping duplicate', [
                'offer_id' => $offer->id,
                'sent_at' => $offer->sent_at->toIso8601String(),
            ]);
            return;
        }

        if ($offer->expires_at->isPast()) {
            Log::info('[AutoFill] SendVkSlotOfferJob: offer already expired', [
                'offer_id' => $offer->id,
            ]);
            return;
        }

        $request = $offer->request;
        $opportunity = $offer->opportunity;
        $client = $request?->client;

        if ($request === null || $opportunity === null || $client === null) {
            Log::warning('[AutoFill] SendVkSlotOfferJob: missing relations', [
                'offer_id' => $offer->id,
            ]);
            $this->invalidateAndRematch($offerService, $offer, SlotInvalidationReason::MissingRelations);
            return;
        }

        if ($request->delivery_channel !== SlotRequestDeliveryChannel::Vk) {
            Log::warning('[AutoFill] SendVkSlotOfferJob: not VK channel', [
                'offer_id' => $offer->id,
                'channel' => $request->delivery_channel?->value,
            ]);
            $this->invalidateAndRematch($offerService, $offer, SlotInvalidationReason::UnsupportedDeliveryChannel);
            return;
        }

        if (empty($client->vk_id)) {
            Log::warning('[AutoFill] SendVkSlotOfferJob: client missing vk_id', [
                'offer_id' => $offer->id,
                'client_id' => $client->id,
            ]);
            $this->invalidateAndRematch($offerService, $offer, SlotInvalidationReason::MissingVkIdentity);
            return;
        }

        $master = $request->master ?? $opportunity->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        $serviceName = $opportunity->masterService?->catalog?->title
            ?? $request->appointment?->display_name
            ?? '';

        $oldDateTime = $request->appointment?->start_time
            ? $request->appointment->start_time->timezone($tz)->format('d.m.Y H:i')
            : '';

        $newDateTime = $opportunity->start_time->timezone($tz)->format('d.m.Y H:i');

        $text = "Освободилось время раньше\n\n"
            . ($serviceName ? "{$serviceName}\n" : '')
            . ($oldDateTime ? "Было: {$oldDateTime}\n" : '')
            . "Можно перенести на: {$newDateTime}\n\n"
            . 'Перенести запись?';

        $keyboard = [
            'one_time' => false,
            'inline' => true,
            'buttons' => [
                [[
                    'action' => [
                        'type' => 'callback',
                        'label' => 'Перенести',
                        'payload' => json_encode(
                            ['command' => 'af_accept_' . $offer->id],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ],
                    'color' => 'positive',
                ]],
                [[
                    'action' => [
                        'type' => 'callback',
                        'label' => 'Не подходит',
                        'payload' => json_encode(
                            ['command' => 'af_decline_' . $offer->id],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ],
                    'color' => 'negative',
                ]],
            ],
        ];

        $mid = $vkApi->sendMessageWithKeyboard((string) $client->vk_id, $text, $keyboard);

        if ($mid === null) {
            Log::warning('[AutoFill] SendVkSlotOfferJob: VK API send failed, will retry', [
                'offer_id' => $offer->id,
                'vk_id' => $client->vk_id,
            ]);
            throw new \Exception('VK API failed to send slot offer message');
        }

        $offer->update([
            'sent_at' => now(),
            'delivery_mid' => $mid,
        ]);

        Log::info('[AutoFill] SendVkSlotOfferJob: sent', [
            'offer_id' => $offer->id,
            'mid' => $mid,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[AutoFill] SendVkSlotOfferJob: all retries exhausted', [
            'offer_id' => $this->slotOfferId,
            'error' => $exception->getMessage(),
        ]);

        $offer = SlotOffer::find($this->slotOfferId);
        if ($offer === null || $offer->status !== SlotOfferStatus::Pending) {
            return;
        }

        try {
            app(SlotOfferService::class)->invalidate($offer, SlotInvalidationReason::DeliveryFailed);
        } catch (\Throwable $e) {
            Log::warning('[AutoFill] SendVkSlotOfferJob: invalidate on failed() error', [
                'offer_id' => $this->slotOfferId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $opportunityId = $offer->slot_opportunity_id;
        MatchSlotOpportunityJob::dispatch($opportunityId);
    }

    private function invalidateAndRematch(SlotOfferService $offerService, SlotOffer $offer, SlotInvalidationReason $reason): void
    {
        $fresh = SlotOffer::find($offer->id);
        if ($fresh === null || $fresh->status !== SlotOfferStatus::Pending) {
            return;
        }

        try {
            $offerService->invalidate($fresh, $reason);
        } catch (\Throwable $e) {
            Log::warning('[AutoFill] SendVkSlotOfferJob: invalidate failed', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $opportunityId = $offer->slot_opportunity_id;
        DB::afterCommit(fn () => MatchSlotOpportunityJob::dispatch($opportunityId));
    }
}
