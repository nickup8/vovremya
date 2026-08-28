<?php

namespace App\Jobs;

use App\Enums\SlotOfferStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Jobs\MatchSlotOpportunityJob;
use App\Models\SlotOffer;
use App\Services\MaxApiClient;
use App\Services\SlotOfferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendMaxSlotOfferJob implements ShouldQueue
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

    public function handle(MaxApiClient $maxApi, SlotOfferService $offerService): void
    {
        $offer = SlotOffer::with([
            'request.client',
            'request.master',
            'request.appointment',
            'opportunity.masterService',
        ])->find($this->slotOfferId);

        if ($offer === null) {
            Log::info('[AutoFill] SendMaxSlotOfferJob: offer not found', [
                'offer_id' => $this->slotOfferId,
            ]);
            return;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            Log::info('[AutoFill] SendMaxSlotOfferJob: offer not pending', [
                'offer_id' => $offer->id,
                'status' => $offer->status->value,
            ]);
            return;
        }

        if ($offer->expires_at->isPast()) {
            Log::info('[AutoFill] SendMaxSlotOfferJob: offer already expired', [
                'offer_id' => $offer->id,
            ]);
            return;
        }

        $request = $offer->request;
        $opportunity = $offer->opportunity;
        $client = $request?->client;

        if ($request === null || $opportunity === null || $client === null) {
            Log::warning('[AutoFill] SendMaxSlotOfferJob: missing relations', [
                'offer_id' => $offer->id,
            ]);
            $this->invalidateAndRematch($offerService, $offer);
            return;
        }

        if ($request->delivery_channel !== SlotRequestDeliveryChannel::Max) {
            Log::warning('[AutoFill] SendMaxSlotOfferJob: not MAX channel', [
                'offer_id' => $offer->id,
                'channel' => $request->delivery_channel?->value,
            ]);
            $this->invalidateAndRematch($offerService, $offer);
            return;
        }

        if (empty($client->max_id)) {
            Log::warning('[AutoFill] SendMaxSlotOfferJob: client missing max_id', [
                'offer_id' => $offer->id,
                'client_id' => $client->id,
            ]);
            $this->invalidateAndRematch($offerService, $offer);
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

        $attachments = [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [
                    [
                        [
                            'type' => 'callback',
                            'text' => 'Перенести',
                            'payload' => 'af_accept_' . $offer->id,
                        ],
                    ],
                    [
                        [
                            'type' => 'callback',
                            'text' => 'Не подходит',
                            'payload' => 'af_decline_' . $offer->id,
                        ],
                    ],
                ],
            ],
        ]];

        $mid = $maxApi->sendMessage($client->max_id, $text, ['attachments' => $attachments]);

        if ($mid === null) {
            Log::warning('[AutoFill] SendMaxSlotOfferJob: MAX API send failed', [
                'offer_id' => $offer->id,
                'client_max_id' => $client->max_id,
            ]);
            $this->invalidateAndRematch($offerService, $offer);
            return;
        }

        Log::info('[AutoFill] SendMaxSlotOfferJob: sent', [
            'offer_id' => $offer->id,
            'mid' => $mid,
        ]);
    }

    private function invalidateAndRematch(SlotOfferService $offerService, SlotOffer $offer): void
    {
        $fresh = SlotOffer::find($offer->id);
        if ($fresh === null || $fresh->status !== SlotOfferStatus::Pending) {
            return;
        }

        try {
            $offerService->invalidate($fresh);
        } catch (\Throwable $e) {
            Log::warning('[AutoFill] SendMaxSlotOfferJob: invalidate failed', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $opportunityId = $offer->slot_opportunity_id;
        DB::afterCommit(fn () => MatchSlotOpportunityJob::dispatch($opportunityId));
    }
}
