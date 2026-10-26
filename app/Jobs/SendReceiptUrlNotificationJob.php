<?php

namespace App\Jobs;

use App\Enums\PaymentReceiptStatus;
use App\Models\PaymentReceipt;
use App\Models\ReceiptChannelDelivery;
use App\Models\User;
use App\Services\MaxApiClient;
use App\Services\VkApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Duplicates a receipt URL to the workspace owner's messenger channels
 * (MAX / VK) after the attach transaction has committed.
 *
 * Contract:
 * - only the receipt id is passed; the CURRENT receipt_url is re-read on
 *   every attempt, so a stale job never sends a replaced link;
 * - channels are independent: a MAX failure never blocks VK and vice
 *   versa; each successful channel is recorded in receipt_channel_deliveries
 *   and is skipped on the next run;
 * - a channel that fails (API returns no id, or throws) makes the job throw
 *   so the queue's bounded retries apply; failures are never recorded as
 *   deliveries;
 * - channels the owner has not enabled (no id or notifications flag off)
 *   are skipped without error;
 * - not exactly-once: if the API accepts a message but the delivery row is
 *   not written (crash between the two), a retry may send a duplicate —
 *   duplicates are bounded by tries and per-channel checks.
 */
class SendReceiptUrlNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $paymentReceiptId,
    ) {}

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(MaxApiClient $maxApi, VkApiClient $vkApi): void
    {
        $receipt = PaymentReceipt::with('paymentAttempt.billingCycle.workspace.owner')
            ->find($this->paymentReceiptId);

        if ($receipt === null
            || $receipt->status !== PaymentReceiptStatus::Attached
            || $receipt->receipt_url === null) {
            return;
        }

        $owner = $receipt->paymentAttempt?->billingCycle?->workspace?->owner;

        if ($owner === null) {
            return;
        }

        $url = $receipt->receipt_url;
        $text = "Чек об оплате подписки\n\nЧек готов. Откройте его по ссылке:\n{$url}";

        $failures = [];

        if ($this->maxEnabled($owner)) {
            if ($this->alreadyDelivered($receipt, 'max', $url)) {
                // already sent for this exact URL — skip silently
            } else {
                $mid = null;

                try {
                    $mid = $maxApi->sendMessage($owner->max_id, $text);
                } catch (\Throwable $e) {
                    Log::warning('MAX receipt URL delivery threw', [
                        'receipt_id' => $receipt->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($mid !== null) {
                    $this->recordDelivery($receipt, 'max', $url);
                } else {
                    $failures[] = 'max';
                }
            }
        }

        if ($this->vkEnabled($owner)) {
            if ($this->alreadyDelivered($receipt, 'vk', $url)) {
                // already sent for this exact URL — skip silently
            } else {
                $mid = null;

                try {
                    // Both messengers render a bare URL in the text as a
                    // link — no keyboard required, no per-peer keyboard
                    // support variance.
                    $mid = $vkApi->sendMessage($owner->vk_id, $text);
                } catch (\Throwable $e) {
                    Log::warning('VK receipt URL delivery threw', [
                        'receipt_id' => $receipt->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($mid !== null) {
                    $this->recordDelivery($receipt, 'vk', $url);
                } else {
                    $failures[] = 'vk';
                }
            }
        }

        if ($failures !== []) {
            // Bounded retries come from $tries/backoff; a partial success
            // (the other channel recorded above) is kept across attempts.
            throw new \RuntimeException(
                'Receipt URL messenger delivery failed for channel(s): '.implode(', ', $failures),
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendReceiptUrlNotificationJob: all retries exhausted', [
            'receipt_id' => $this->paymentReceiptId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function maxEnabled(User $owner): bool
    {
        return ! empty($owner->max_id) && $owner->max_notifications === true;
    }

    private function vkEnabled(User $owner): bool
    {
        return ! empty($owner->vk_id) && $owner->vk_notifications === true;
    }

    private function alreadyDelivered(PaymentReceipt $receipt, string $channel, string $url): bool
    {
        return ReceiptChannelDelivery::where('payment_receipt_id', $receipt->getKey())
            ->where('channel', $channel)
            ->where('receipt_url_hash', ReceiptChannelDelivery::hashUrl($url))
            ->exists();
    }

    private function recordDelivery(PaymentReceipt $receipt, string $channel, string $url): void
    {
        ReceiptChannelDelivery::firstOrCreate(
            [
                'payment_receipt_id' => $receipt->getKey(),
                'channel' => $channel,
                'receipt_url_hash' => ReceiptChannelDelivery::hashUrl($url),
            ],
            [
                'receipt_url' => $url,
                'delivered_at' => now(),
            ],
        );
    }
}
