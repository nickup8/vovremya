<?php

namespace App\Jobs;

use App\Enums\PaymentReceiptStatus;
use App\Models\PaymentReceipt;
use App\Models\ReceiptChannelDelivery;
use App\Models\ReceiptDeliveryEvent;
use App\Models\User;
use App\Services\MaxApiClient;
use App\Services\VkApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Duplicates one receipt link-change EVENT to the workspace owner's
 * messenger channels (MAX / VK).
 *
 * Contract:
 * - the payload is the receipt_delivery_events row id; (receipt,
 *   url_version) is the event identity. A job whose event is superseded
 *   (the receipt moved to a newer url_version) is stale and returns without
 *   sending — the newer event's own job owns the current URL, so an old
 *   delivery can neither duplicate nor suppress it;
 * - text: first attach (version 1) is «Чек об оплате подписки», every real
 *   change is «Ссылка на чек обновлена», both carrying the full event URL;
 * - before HTTP a short claim transaction inserts/locks the per
 *   (event, channel) row in receipt_channel_deliveries. Two overlapping
 *   workers serialize there: the second sees the live lease and backs off
 *   (release + delayed re-check) instead of sending. NO SQL transaction is
 *   held while the API call runs;
 * - the claim lease (60s) exceeds both this job's timeout (30s) and the API
 *   clients' HTTP timeout (10s), so a live claimer is never preempted
 *   mid-request; an expired lease (crashed worker) is reclaimable;
 * - channels are independent: a MAX failure — API error, null message id,
 *   or a failure writing the MAX result row — never stops VK. Every failed
 *   outcome releases its claim so the bounded retry can re-attempt exactly
 *   the unfinished channel; successes are recorded (delivered + provider
 *   message id) and skipped on the next run. A failed send is never
 *   recorded as delivered;
 * - channels the owner has not enabled (no id or notifications flag off)
 *   are skipped without error;
 * - not exactly-once: when the API accepts a message but the result write
 *   fails (or the HTTP outcome is unknown — timeout), the retry may send a
 *   duplicate. Duplicates are bounded by tries, the claim lease and the
 *   per-channel delivery rows; a message already accepted by MAX/VK can
 *   never be cancelled from here.
 */
class SendReceiptUrlNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Must stay below the claim lease (LEASE_SECONDS) so a running claimer is
    // never considered dead while this job is still executing.
    public int $timeout = 30;

    // Aligned with the job: > timeout (30s) + one API HTTP call (10s).
    private const LEASE_SECONDS = 60;

    // Re-check window when another worker holds the claim right now.
    private const BUSY_RETRY_DELAY = 15;

    public function __construct(
        public string $eventId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(MaxApiClient $maxApi, VkApiClient $vkApi): void
    {
        $event = ReceiptDeliveryEvent::find($this->eventId);

        if ($event === null) {
            return;
        }

        $receipt = PaymentReceipt::with('paymentAttempt.billingCycle.workspace.owner')
            ->find($event->payment_receipt_id);

        if ($receipt === null
            || $receipt->status !== PaymentReceiptStatus::Attached
            || $receipt->receipt_url === null
            || $receipt->url_version !== $event->url_version) {
            // Stale event: the link changed again after this event was
            // committed. The newer event's job delivers the current URL.
            return;
        }

        $owner = $receipt->paymentAttempt?->billingCycle?->workspace?->owner;

        if ($owner === null) {
            return;
        }

        $url = $event->receipt_url;
        $text = $this->messageText($event->url_version, $url);

        $failures = [];
        $busy = false;

        if ($this->maxEnabled($owner)) {
            $result = $this->deliver(
                'max',
                $event,
                fn (): ?string => $maxApi->sendMessage($owner->max_id, $text),
            );

            if ($result === 'failed') {
                $failures[] = 'max';
            } elseif ($result === 'busy') {
                $busy = true;
            } elseif ($result === 'stale') {
                return;
            }
        }

        if ($this->vkEnabled($owner)) {
            $result = $this->deliver(
                'vk',
                $event,
                // Both messengers render a bare URL in the text as a link.
                fn (): ?string => $vkApi->sendMessage($owner->vk_id, $text),
            );

            if ($result === 'failed') {
                $failures[] = 'vk';
            } elseif ($result === 'busy') {
                $busy = true;
            } elseif ($result === 'stale') {
                return;
            }
        }

        if ($failures !== []) {
            // Partial successes above are already recorded and skipped next
            // attempt; only the failed channels are retried.
            throw new \RuntimeException(
                'Receipt URL messenger delivery failed for channel(s): '.implode(', ', $failures),
            );
        }

        if ($busy && $this->job !== null) {
            // Another worker owns the in-flight claim — re-check after it
            // either records the delivery or releases the claim.
            $this->job->release(self::BUSY_RETRY_DELAY);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendReceiptUrlNotificationJob: all retries exhausted', [
            'event_id' => $this->eventId,
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

    /**
     * Deliver one channel of one event.
     *
     * @param  callable(): ?string  $send  performs the HTTP call
     * @return 'delivered'|'already'|'busy'|'stale'|'failed'
     */
    private function deliver(string $channel, ReceiptDeliveryEvent $event, callable $send): string
    {
        try {
            $claim = $this->claim($event, $channel);
        } catch (\Throwable $e) {
            Log::warning('Receipt delivery claim failed', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        if ($claim !== 'claimed') {
            // 'delivered' — already messaged for this event (skip);
            // 'busy'     — another worker is inside its claim window.
            return $claim;
        }

        // Final freshness gate right before the network call: if the link
        // was replaced after this job started, drop the claim and let the
        // newer event's job send instead.
        if (! $this->eventIsCurrent($event)) {
            $this->releaseClaim($event, $channel);

            return 'stale';
        }

        try {
            $providerMessageId = $send(); // HTTP — outside any DB transaction
        } catch (\Throwable $e) {
            Log::warning('Receipt URL delivery threw', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            $this->releaseClaim($event, $channel);

            return 'failed';
        }

        if ($providerMessageId === null) {
            // The client returns null for HTTP errors, non-2xx bodies and
            // missing message ids (and throws are handled above) — none of
            // these is a delivery.
            $this->releaseClaim($event, $channel);

            return 'failed';
        }

        try {
            $this->recordDelivery($event, $channel, $providerMessageId);
        } catch (\Throwable $e) {
            // The message was accepted but the result row could not be
            // written. Releasing the claim lets the retry re-attempt this
            // channel (possible bounded duplicate) instead of stranding it
            // behind the lease; VK is unaffected either way.
            Log::error('Receipt delivery result write failed', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            $this->releaseClaim($event, $channel);

            return 'failed';
        }

        return 'delivered';
    }

    /**
     * Claim (event, channel) before HTTP. Runs in its own short
     * transaction that is committed before any network call.
     *
     * @return 'claimed'|'delivered'|'busy'
     */
    private function claim(ReceiptDeliveryEvent $event, string $channel): string
    {
        try {
            return DB::transaction(function () use ($event, $channel): string {
                $row = ReceiptChannelDelivery::query()
                    ->where('payment_receipt_id', $event->payment_receipt_id)
                    ->where('channel', $channel)
                    ->where('url_version', $event->url_version)
                    ->lockForUpdate()
                    ->first();

                if ($row !== null) {
                    if ($row->status === ReceiptChannelDelivery::STATUS_DELIVERED) {
                        return 'delivered';
                    }

                    if ($row->lease_expires_at !== null && $row->lease_expires_at->isFuture()) {
                        // A live worker holds this claim — do not send.
                        return 'busy';
                    }

                    // Lease expired: the previous claimer crashed inside or
                    // after HTTP. Reclaim and try again.
                    $row->update([
                        'receipt_url' => $event->receipt_url,
                        'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                    ]);

                    return 'claimed';
                }

                ReceiptChannelDelivery::create([
                    'payment_receipt_id' => $event->payment_receipt_id,
                    'channel' => $channel,
                    'url_version' => $event->url_version,
                    'receipt_url' => $event->receipt_url,
                    'status' => ReceiptChannelDelivery::STATUS_PENDING,
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                ]);

                return 'claimed';
            });
        } catch (QueryException $e) {
            // Unique (receipt, channel, version) race: another worker
            // inserted the claim between our read and our insert.
            $sqlState = $e->errorInfo[0] ?? '';
            if (in_array($sqlState, ['23505', '23000'], true)
                || stripos($e->getMessage(), 'unique') !== false) {
                return 'busy';
            }

            throw $e;
        }
    }

    /**
     * Give up the claim after a failed/uncertain outcome so the retry (or
     * another worker) can re-attempt this channel instead of waiting for
     * the lease. Never touches a delivered row.
     */
    private function releaseClaim(ReceiptDeliveryEvent $event, string $channel): void
    {
        ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $event->payment_receipt_id)
            ->where('channel', $channel)
            ->where('url_version', $event->url_version)
            ->where('status', ReceiptChannelDelivery::STATUS_PENDING)
            ->delete();
    }

    private function eventIsCurrent(ReceiptDeliveryEvent $event): bool
    {
        $version = PaymentReceipt::query()
            ->whereKey($event->payment_receipt_id)
            ->value('url_version');

        return $version !== null && (int) $version === $event->url_version;
    }

    /**
     * Only successful sends are recorded — with the provider message id as
     * evidence. A failure to write leaves the claim released (see caller).
     */
    private function recordDelivery(ReceiptDeliveryEvent $event, string $channel, string $providerMessageId): void
    {
        $row = ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $event->payment_receipt_id)
            ->where('channel', $channel)
            ->where('url_version', $event->url_version)
            ->where('status', ReceiptChannelDelivery::STATUS_PENDING)
            ->first();

        if ($row === null) {
            // The claim expired and another worker took over; its own record
            // write is authoritative — do not fabricate one here.
            return;
        }

        $row->status = ReceiptChannelDelivery::STATUS_DELIVERED;
        $row->provider_message_id = $providerMessageId;
        $row->delivered_at = Carbon::now();
        $row->lease_expires_at = null;
        $row->save();
    }

    private function messageText(int $urlVersion, string $url): string
    {
        if ($urlVersion > 1) {
            return "Ссылка на чек обновлена\n\nОткройте чек по обновлённой ссылке:\n{$url}";
        }

        return "Чек об оплате подписки\n\nЧек готов. Откройте его по ссылке:\n{$url}";
    }
}
