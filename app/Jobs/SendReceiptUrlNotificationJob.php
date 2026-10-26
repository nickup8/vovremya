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
use Illuminate\Support\Str;

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
 *   (event, channel) row in receipt_channel_deliveries. EVERY capture —
 *   the first insert and the takeover of an expired lease — writes a fresh
 *   claim_token, and that token is handed to the executor, which keeps it
 *   for the rest of the channel pass. Two overlapping workers serialize on
 *   the row: the second sees the live lease and backs off (release +
 *   re-check) instead of sending. NO SQL transaction is held while the API
 *   call runs;
 * - the success write and the claim release are compare-and-swap
 *   operations conditioned on (event key, status=pending, own claim_token):
 *   a worker whose lease was taken over can neither stamp its result over
 *   the new owner's row nor delete the new owner's claim, and a condition
 *   that does not match is never counted as that worker's confirmed
 *   success. Nothing reads a row and then saves/deletes it unconditionally;
 * - the claim lease (60s) exceeds both this job's timeout (30s) and the API
 *   clients' HTTP timeout (10s), so a live claimer is never preempted
 *   mid-request; an expired lease (crashed worker) is reclaimable;
 * - waiting and failing have separate budgets. A busy claim is re-checked
 *   after the lease that was actually observed, and the whole waiting
 *   window is bounded by the preserved $retryUntil deadline (frozen into
 *   the queue payload at dispatch), so such a release grows queue attempts
 *   without ever failing the job. Real failed sends are counted apart, by
 *   $maxExceptions — a held lease therefore cannot spend the budget of an
 *   actual API error, and neither budget is open-ended;
 * - channels are independent: the whole pass of one channel — freshness
 *   check, HTTP call, claim release, result write — sits behind one guard,
 *   so a failure (including a failing cleanup) is logged with its own step
 *   and never masks the primary outcome nor stops the other channel. Every
 *   failed outcome releases its claim so the bounded retry can re-attempt
 *   exactly the unfinished channel; successes are recorded (delivered +
 *   provider message id) and skipped on the next run. A failed send is
 *   never recorded as delivered;
 * - channels the owner has not enabled (no id or notifications flag off)
 *   are skipped without error;
 * - not exactly-once: when the API accepts a message but the result write
 *   fails (or the HTTP outcome is unknown — timeout), the retry may send a
 *   duplicate. Duplicates are bounded by the deadline, the claim lease and
 *   the per-channel delivery rows; a message already accepted by MAX/VK can
 *   never be cancelled from here.
 */
class SendReceiptUrlNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Historical attempts cap. Busy re-checks release the job and grow the
    // queue's attempts counter, so this counter alone no longer decides the
    // job's fate: waiting is bounded by $retryUntil, real send failures by
    // $maxExceptions (see the class contract).
    public int $tries = 3;

    // Budget of REAL failed sends: every run that leaves a channel
    // unfinished throws once, and the queue fails the job on the
    // $maxExceptions-th such exception. Busy re-checks release instead of
    // throwing, so they never consume this counter.
    public int $maxExceptions = 3;

    // Waiting budget: an absolute timestamp frozen into the queue payload at
    // dispatch and kept unchanged across every release, so successive busy
    // re-checks share one deadline instead of restarting the clock.
    public int $retryUntil;

    // Must stay below the claim lease (LEASE_SECONDS) so a running claimer is
    // never considered dead while this job is still executing.
    public int $timeout = 30;

    // Aligned with the job: > timeout (30s) + one API HTTP call (10s).
    private const LEASE_SECONDS = 60;

    // A re-check must land strictly after the holder's lease expires.
    private const LEASE_MARGIN_SECONDS = 2;

    // Overall wall-clock budget for one delivery intent: it has to cover
    // several 60s lease wait-outs plus the backoff schedule of the real-error
    // retries (5 + 15 + 30), while keeping an undelivered event from
    // retrying forever.
    private const JOB_DEADLINE_SECONDS = 600;

    // Set while another worker owns a claim we still need; drives the single
    // re-check release at the end of handle(). Transient: reset every run.
    private bool $needsRecheck = false;

    private ?\DateTimeInterface $busyUntil = null;

    public function __construct(public string $eventId)
    {
        $this->retryUntil = now()->addSeconds(self::JOB_DEADLINE_SECONDS)->getTimestamp();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(MaxApiClient $maxApi, VkApiClient $vkApi): void
    {
        $this->needsRecheck = false;
        $this->busyUntil = null;

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

        if ($this->maxEnabled($owner)) {
            $result = $this->deliver(
                'max',
                $event,
                fn (): ?string => $maxApi->sendMessage($owner->max_id, $text),
            );

            if ($result === 'failed') {
                $failures[] = 'max';
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

        if ($this->needsRecheck && $this->job !== null) {
            // Another worker owns the in-flight claim: come back when the
            // lease we actually observed expires, not on a fixed short tick
            // that would drain the attempts counter before the lease does.
            // The wait stays bounded — by that lease locally and by the
            // job's preserved deadline overall.
            $this->job->release($this->busyRecheckDelay());
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
     * Deliver one channel of one event — the WHOLE pass of that channel.
     *
     * Nothing may escape this method: an exception from any step (claim,
     * freshness gate, HTTP, result write) is contained here and reported as
     * this channel's failure, so MAX can never abort the VK pass that runs
     * after it. The claim release always goes through releaseClaimSafely,
     * which logs a cleanup error on its own instead of replacing the
     * primary one.
     *
     * @param  callable(): ?string  $send  performs the HTTP call
     * @return 'delivered'|'already'|'busy'|'stale'|'failed'
     */
    private function deliver(string $channel, ReceiptDeliveryEvent $event, callable $send): string
    {
        $claimToken = null;
        $step = 'claim';

        try {
            $claim = $this->claim($event, $channel);

            if ($claim['state'] !== 'claimed') {
                // 'delivered' — already messaged for this event (skip);
                // 'busy'     — another worker is inside its claim window.
                if ($claim['state'] === 'busy') {
                    $this->noteBusyRecheck($claim['lease_expires_at'] ?? null);
                }

                return $claim['state'];
            }

            $claimToken = $claim['token'];

            // Final freshness gate right before the network call: if the link
            // was replaced after this job started, drop the claim and let the
            // newer event's job send instead.
            $step = 'freshness';
            if (! $this->eventIsCurrent($event)) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);

                return 'stale';
            }

            $step = 'send';
            $providerMessageId = $send(); // HTTP — outside any DB transaction

            if ($providerMessageId === null) {
                // The client returns null for HTTP errors, non-2xx bodies and
                // missing message ids (and throws are handled below) — none of
                // these is a delivery.
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);

                return 'failed';
            }

            $step = 'record';
            if (! $this->recordDelivery($event, $channel, $claimToken, $providerMessageId)) {
                // The CAS did not apply: while we were inside HTTP the claim
                // changed hands (or was finalised) by its new owner. This is
                // NOT our confirmed success — the new owner's row stays
                // authoritative, so re-check it on a later attempt instead of
                // writing over it.
                $this->noteBusyRecheck(null);

                return 'busy';
            }

            return 'delivered';
        } catch (\Throwable $e) {
            Log::error('Receipt URL delivery pass failed', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'step' => $step,
                'error' => $e->getMessage(),
            ]);

            if ($claimToken !== null) {
                // Whatever failed above, give OUR claim back so the bounded
                // retry can re-attempt this channel instead of waiting for
                // the lease. A failing release is logged separately and must
                // not replace the primary error recorded here.
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
            }

            return 'failed';
        }
    }

    /**
     * Claim (event, channel) before HTTP. Runs in its own short transaction
     * that is committed before any network call.
     *
     * Every capture gets a fresh claim_token — first insert AND takeover of
     * an expired lease — which is returned to the executor: from then on the
     * success write and the release only ever match rows carrying it.
     *
     * @return array{state: 'claimed'|'delivered'|'busy', token?: string, lease_expires_at?: \DateTimeInterface|null}
     */
    private function claim(ReceiptDeliveryEvent $event, string $channel): array
    {
        $claimToken = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($event, $channel, $claimToken): array {
                $row = ReceiptChannelDelivery::query()
                    ->where('payment_receipt_id', $event->payment_receipt_id)
                    ->where('channel', $channel)
                    ->where('url_version', $event->url_version)
                    ->lockForUpdate()
                    ->first();

                if ($row !== null) {
                    if ($row->status === ReceiptChannelDelivery::STATUS_DELIVERED) {
                        return ['state' => 'delivered'];
                    }

                    if ($row->lease_expires_at !== null && $row->lease_expires_at->isFuture()) {
                        // A live worker holds this claim — do not send.
                        return [
                            'state' => 'busy',
                            'lease_expires_at' => $row->lease_expires_at,
                        ];
                    }

                    // Lease expired: the previous claimer crashed inside or
                    // after HTTP. Take over with OUR token, so anything that
                    // claimer still does late can no longer match this row.
                    $row->update([
                        'receipt_url' => $event->receipt_url,
                        'claim_token' => $claimToken,
                        'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                    ]);

                    return ['state' => 'claimed', 'token' => $claimToken];
                }

                ReceiptChannelDelivery::create([
                    'payment_receipt_id' => $event->payment_receipt_id,
                    'channel' => $channel,
                    'url_version' => $event->url_version,
                    'receipt_url' => $event->receipt_url,
                    'status' => ReceiptChannelDelivery::STATUS_PENDING,
                    'claim_token' => $claimToken,
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                ]);

                return ['state' => 'claimed', 'token' => $claimToken];
            });
        } catch (QueryException $e) {
            // Unique (receipt, channel, version) race: another worker
            // inserted the claim between our read and our insert.
            $sqlState = $e->errorInfo[0] ?? '';
            if (in_array($sqlState, ['23505', '23000'], true)
                || stripos($e->getMessage(), 'unique') !== false) {
                return [
                    'state' => 'busy',
                    'lease_expires_at' => $this->currentLease($event, $channel),
                ];
            }

            throw $e;
        }
    }

    /**
     * Give up OUR claim after a failed/uncertain outcome so the retry (or
     * another worker) can re-attempt this channel instead of waiting for the
     * lease. Compare-and-swap delete: the row must still be our pending
     * claim, so a superseded worker can never drop the new owner's claim.
     * Never touches a delivered row.
     */
    private function releaseClaim(ReceiptDeliveryEvent $event, string $channel, string $claimToken): void
    {
        ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $event->payment_receipt_id)
            ->where('channel', $channel)
            ->where('url_version', $event->url_version)
            ->where('status', ReceiptChannelDelivery::STATUS_PENDING)
            ->where('claim_token', $claimToken)
            ->delete();
    }

    /**
     * Cleanup wrapper that must never throw: the primary outcome of this
     * channel has already been decided (and logged), so a failing release is
     * reported as its own error instead of replacing that outcome or
     * aborting the channels that follow.
     */
    private function releaseClaimSafely(ReceiptDeliveryEvent $event, string $channel, string $claimToken, string $reason): void
    {
        try {
            $this->releaseClaim($event, $channel, $claimToken);
        } catch (\Throwable $e) {
            Log::error('Receipt delivery claim release failed', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function eventIsCurrent(ReceiptDeliveryEvent $event): bool
    {
        $version = PaymentReceipt::query()
            ->whereKey($event->payment_receipt_id)
            ->value('url_version');

        return $version !== null && (int) $version === $event->url_version;
    }

    /**
     * Record an accepted send as THIS executor's success — compare-and-swap.
     *
     * The row is read under a lock in the same transaction as the write and
     * must still be our pending claim (same claim_token), so a claim that
     * was taken over mid-flight is never overwritten, and a condition that
     * does not match yields false: the caller must not treat the send as a
     * confirmed recorded success then.
     *
     * Only successful sends are recorded — with the provider message id as
     * evidence. A failure to write leaves the claim released (see caller).
     */
    private function recordDelivery(ReceiptDeliveryEvent $event, string $channel, string $claimToken, string $providerMessageId): bool
    {
        return DB::transaction(function () use ($event, $channel, $claimToken, $providerMessageId): bool {
            $row = ReceiptChannelDelivery::query()
                ->where('payment_receipt_id', $event->payment_receipt_id)
                ->where('channel', $channel)
                ->where('url_version', $event->url_version)
                ->where('status', ReceiptChannelDelivery::STATUS_PENDING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // The claim expired and another worker took over (or the row
                // was finalised); its own record write is authoritative — do
                // not fabricate one here.
                return false;
            }

            $row->status = ReceiptChannelDelivery::STATUS_DELIVERED;
            $row->provider_message_id = $providerMessageId;
            $row->delivered_at = Carbon::now();
            $row->lease_expires_at = null;
            $row->save();

            return true;
        });
    }

    /**
     * Current lease of (event, channel), if any row is still there — used
     * when a claim is already held by someone else and no lease came with
     * it (unique-insert race).
     */
    private function currentLease(ReceiptDeliveryEvent $event, string $channel): ?\DateTimeInterface
    {
        return ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $event->payment_receipt_id)
            ->where('channel', $channel)
            ->where('url_version', $event->url_version)
            ->first()
            ?->lease_expires_at;
    }

    /**
     * Remember that this channel still needs a re-check (its claim belongs
     * to another worker right now) and, when we saw one, how long that
     * worker's lease lasts.
     */
    private function noteBusyRecheck(?\DateTimeInterface $leaseExpiresAt): void
    {
        $this->needsRecheck = true;

        if ($leaseExpiresAt !== null
            && ($this->busyUntil === null
                || $leaseExpiresAt->getTimestamp() > $this->busyUntil->getTimestamp())) {
            $this->busyUntil = $leaseExpiresAt;
        }
    }

    /**
     * Delay before re-checking a claim held by another worker: wait out the
     * observed lease (plus a margin so we land after its expiry) instead of
     * waking up every 15s and spending queue attempts on a lease that is
     * still live. Without an observed lease there is nothing to wait for —
     * re-check on the next attempt. The job's preserved deadline caps the
     * whole waiting window at the queue level.
     */
    private function busyRecheckDelay(): int
    {
        if ($this->busyUntil === null) {
            return 1;
        }

        $landAfter = $this->busyUntil->getTimestamp() + self::LEASE_MARGIN_SECONDS;

        return max(1, $landAfter - now()->getTimestamp());
    }

    private function messageText(int $urlVersion, string $url): string
    {
        if ($urlVersion > 1) {
            return "Ссылка на чек обновлена\n\nОткройте чек по обновлённой ссылке:\n{$url}";
        }

        return "Чек об оплате подписки\n\nЧек готов. Откройте его по ссылке:\n{$url}";
    }
}
