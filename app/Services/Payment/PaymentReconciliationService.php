<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\PaymentAttempt;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentReconciliationService
{
    /**
     * Statuses that are an actual provider verdict: only these are handed to
     * the state machine for a locally timed-out attempt. Anything else
     * (NEW → Unknown, AUTHORIZED → Processing) is still in flight at the
     * bank, so the local timeout and the checkout block built on it survive.
     */
    private const FINAL_VERDICTS = [
        PaymentAttemptStatus::Succeeded,
        PaymentAttemptStatus::FailedTerminal,
        PaymentAttemptStatus::Refunded,
    ];

    /**
     * TTL of the per-attempt status-check lock: it has to cover the WHOLE
     * coordinated check with a margin. One bank method may legally spend
     * the entire global HTTP retry budget — up to 6 attempts of 20s
     * response timeout plus 9.3s of retry delays (AppServiceProvider),
     * ≈ 130s. A recovery-enabled check may run TWO such methods
     * sequentially under the same lock (the status lookup — GetState or
     * CheckOrder — followed by GetQr for a lost SBP link), so the TTL
     * must outlive ≈ 260s; 300s leaves ~40s of headroom and still frees
     * an attempt within minutes if the process dies mid-check. The retry
     * policy itself is not owned here and stays untouched.
     */
    private const CHECK_LOCK_TTL_SECONDS = 300;

    public function __construct(
        private PaymentGatewayManager $gatewayManager,
        private PaymentTransitionService $transitionService,
    ) {}

    /**
     * Reconcile stale payment attempts.
     *
     * @return array{processed: int, errors: int}
     */
    public function reconcile(): array
    {
        $config = config('billing.reconciliation');
        $batchSize = $config['batch_size'] ?? 50;
        $timeoutBatchSize = $config['timeout_batch_size'] ?? 10;
        $freshGraceSeconds = $config['fresh_grace_seconds'] ?? 60;
        $backoff = $config['backoff'] ?? [60, 120, 300, 900, 1800, 3600];
        $maxAgeWithProviderId = $config['max_age_with_provider_id'] ?? 86400;
        $maxAgeWithoutProviderId = $config['max_age_without_provider_id'] ?? 1800;

        $freshThreshold = now()->subSeconds($freshGraceSeconds);

        // Select stale attempts
        $attempts = PaymentAttempt::whereIn('status', [
            PaymentAttemptStatus::Created,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        ])
            ->where('created_at', '<', $freshThreshold)
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        // Separate, bounded queue for local timeouts awaiting a verdict —
        // its own budget, so it never displaces the ordinary batch above.
        // Snapshotted BEFORE this run's age-releases: a row released below
        // is only polled from the next pass onwards. Only rows due under
        // the backoff take one of its slots (see selectUnresolvedTimeouts).
        $timeoutAttempts = $this->selectUnresolvedTimeouts($timeoutBatchSize, $backoff);

        $processed = 0;
        $errors = 0;

        foreach ($attempts as $attempt) {
            try {
                $this->reconcileAttempt($attempt, $backoff, $maxAgeWithProviderId, $maxAgeWithoutProviderId);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('Reconciliation failed for attempt', [
                    'attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($timeoutAttempts as $attempt) {
            try {
                $this->reconcileTimeoutAttempt($attempt, $backoff);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('Reconciliation failed for attempt', [
                    'attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Manual GetState for a SINGLE attempt — the shared mechanism behind the
     * POST check button. It asks the very same per-attempt lock, cooldown and
     * fresh-row re-read as both automatic queues, so a click, a tab and a cron
     * pass can never produce two GetState calls for one attempt.
     *
     * This path never writes the automatic queue's poll metadata: poll_count
     * is neither advanced nor reset by a manual request. Null, transport
     * errors and non-final bank answers (NEW / AUTHORIZED) keep the attempt
     * waiting exactly as it was — only a final verdict goes through the
     * existing PaymentTransitionService. A NEW / AUTHORIZED answer may also
     * restore this attempt's lost SBP link via a repeated GetQr (see
     * maybeRecoverSbpQrLink) — same lock, never a new Init.
     *
     * @return array{attempt: PaymentAttempt, checked: bool}
     *                                                       `checked` = an actual GetState happened (false =
     *                                                       lock held, cooldown active, or nothing to check).
     */
    public function checkAttemptNow(PaymentAttempt $attempt): array
    {
        if ($attempt->provider_payment_id === null || $attempt->provider_payment_id === '') {
            return ['attempt' => $attempt, 'checked' => false];
        }

        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            return ['attempt' => $attempt, 'checked' => false];
        }

        return $this->runStatusCheck(
            $attempt,
            due: fn (PaymentAttempt $fresh): bool => $this->isStatusCheckEligible($fresh)
                && $fresh->provider_payment_id !== null
                && $fresh->provider_payment_id !== ''
                && $this->statusCheckCooldownExpired($fresh),
            handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                $this->applyVerdictOnly($statusUpdate);
            },
            recoverSbpLink: true,
        );
    }

    /**
     * One coordinated status check for a SINGLE attempt: the ONLY place in
     * this service that talks to a gateway, shared by the manual POST check,
     * the post-expiry bypass and both automatic queues.
     *
     * Everything runs under the per-attempt lock, in this order:
     *
     * 1. re-read the attempt from the database — the caller's conditions are
     *    re-checked against that FRESH row, so a stale batch snapshot or a
     *    stale manual request can neither start a second check nor poll an
     *    attempt another actor has just resolved or just checked;
     * 2. provider HTTP, outside every DB transaction — GetState for an
     *    attempt that already carries a PaymentId (CheckOrder instead when
     *    an expired card deadline demands it), CheckOrder recovery for
     *    a tbank checkout attempt that still waits without one;
     * 3. every write this check owes — cooldown marker, poll metadata, the
     *    SBP and card expiry one-shot markers — committed BEFORE the lock
     *    is released;
     * 4. the caller's status handling, under the same lock, so a concurrent
     *    check never meets a half-finished one;
     * 5. optionally ($recoverSbpLink) the repeated GetQr that restores a
     *    lost SBP link of THIS attempt — still under the same lock, which
     *    is why its TTL covers two sequential bank methods (see
     *    CHECK_LOCK_TTL_SECONDS). A GetQr failure is never a verdict and
     *    never touches the attempt's status or PaymentId.
     *
     * A skip (lock held, cooldown still running, row no longer eligible)
     * performs no HTTP and consumes nothing — the one-shot marker included.
     *
     * @param  callable(PaymentAttempt): bool  $due  path conditions, always evaluated on the fresh row
     * @param  callable(PaymentAttempt, ?ProviderStatusUpdate): void  $handle  what an answer means for this path
     * @return array{attempt: PaymentAttempt, checked: bool}
     *                                                       `checked` = an actual GetState happened.
     */
    private function runStatusCheck(
        PaymentAttempt $attempt,
        callable $due,
        callable $handle,
        bool $advancePollMetadata = false,
        bool $consumeSbpExpiry = false,
        bool $consumeCardExpiry = false,
        bool $rethrow = false,
        bool $recoverSbpLink = false,
    ): array {
        $lock = Cache::lock(
            'billing:payment-status-check:'.$attempt->getKey(),
            self::CHECK_LOCK_TTL_SECONDS,
        );

        if (! $lock->get()) {
            // Another check of this attempt is in flight right now
            return ['attempt' => $attempt->refresh(), 'checked' => false];
        }

        try {
            $fresh = PaymentAttempt::query()->whereKey($attempt->getKey())->first();

            if ($fresh === null) {
                return ['attempt' => $attempt, 'checked' => false];
            }

            if (! $due($fresh) || ! $this->gatewayManager->hasGateway($fresh->provider)) {
                // The snapshot the caller held is no longer actionable
                return ['attempt' => $fresh, 'checked' => false];
            }

            try {
                // Provider HTTP — outside any DB transaction. An attempt
                // still waiting WITHOUT a PaymentId is asked about through
                // CheckOrder (PaymentId recovery); one that already carries
                // an id keeps the ordinary GetState — unless an expired
                // card deadline switches it to CheckOrder.
                $statusUpdate = $this->fetchProviderStatus($fresh);
            } catch (\Throwable $e) {
                // The GetState really happened: what this call owes is still
                // written before the lock goes, and an error is never a
                // verdict — the attempt keeps waiting.
                $this->recordStatusCheck($fresh, $advancePollMetadata, $consumeSbpExpiry, $consumeCardExpiry);

                if ($rethrow) {
                    throw $e;
                }

                return ['attempt' => $fresh->refresh(), 'checked' => true];
            }

            // Exactly one set of writes per actual GetState, whatever it said
            $this->recordStatusCheck($fresh, $advancePollMetadata, $consumeSbpExpiry, $consumeCardExpiry);

            $handle($fresh, $statusUpdate);

            if ($recoverSbpLink && $statusUpdate !== null) {
                // Восстановление SBP-ссылки повторным GetQr — после handle,
                // чтобы CheckOrder-путь успел привязать PaymentId; под тем
                // же lock, что и вся проверка. Ошибка GetQr здесь никогда
                // не вердикт: попытка и её PaymentId остаются как есть.
                $this->maybeRecoverSbpQrLink($fresh, $statusUpdate);
            }

            return ['attempt' => $fresh->refresh(), 'checked' => true];
        } finally {
            $lock->release();
        }
    }

    /**
     * Every metadata write an ACTUAL GetState owes, in one short
     * transaction, always before the shared lock is released:
     *
     * - `last_status_check_at` — the cooldown marker shared by every path;
     * - `poll_count` / `last_polled_at` — the automatic queue's backoff,
     *   incremented exactly once per actual call and never reset;
     * - `sbp_expiry_polled_at` — the SBP post-expiry one-shot, consumed
     *   only by the bypass that really reached the bank;
     * - `card_expiry_polled_at` — the card post-expiry one-shot, same
     *   rule: only an actual CheckOrder/GetState consumes it.
     *
     * The row is re-read under a lock and only these keys are rewritten, so
     * a marker or flag written in the meantime (charge_dispatch_started_at,
     * technical_retry, …) survives, the stale snapshot is never written back
     * and metadata is merged rather than replaced.
     */
    private function recordStatusCheck(
        PaymentAttempt $attempt,
        bool $advancePollMetadata,
        bool $consumeSbpExpiry,
        bool $consumeCardExpiry = false,
    ): void {
        DB::transaction(function () use ($attempt, $advancePollMetadata, $consumeSbpExpiry, $consumeCardExpiry) {
            $fresh = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($fresh === null) {
                return;
            }

            $metadata = $this->attemptMetadata($fresh);
            $metadata['last_status_check_at'] = now()->toIso8601String();

            if ($advancePollMetadata) {
                $metadata['poll_count'] = (int) ($metadata['poll_count'] ?? 0) + 1;
                $metadata['last_polled_at'] = now()->toIso8601String();
            }

            if ($consumeSbpExpiry) {
                $metadata['sbp_expiry_polled_at'] = now()->toIso8601String();
            }

            if ($consumeCardExpiry) {
                $metadata['card_expiry_polled_at'] = now()->toIso8601String();
            }

            $fresh->update(['metadata' => $metadata]);
        });
    }

    /**
     * What an answer means for the manual check and for the post-expiry
     * bypass: ONLY a final bank verdict goes through the existing
     * PaymentTransitionService. Null, transport errors and in-flight
     * answers leave the status, the checkout block and the waiting state
     * untouched — a local expiry is never mistaken for a bank refusal.
     */
    private function applyVerdictOnly(?ProviderStatusUpdate $statusUpdate): void
    {
        if ($statusUpdate !== null
            && in_array($statusUpdate->normalizedStatus, self::FINAL_VERDICTS, true)) {
            $this->transitionService->transition($statusUpdate);
        }
    }

    /**
     * What an answer means for the card post-expiry bypass: a known-id
     * answer has already passed identity verification in
     * fetchExpiredCardStatus, so only a FINAL verdict goes through the
     * existing PaymentTransitionService (applyVerdictOnly). A no-id
     * attempt reuses the established CheckOrder recovery: identity proof,
     * bind under row lock with unique protection, then the same final-only
     * continuation. Null / 335 / transport / ambiguous answers leave the
     * attempt waiting — a local deadline is never a bank refusal.
     */
    private function applyCardExpiryVerdict(PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void
    {
        if ($statusUpdate === null) {
            return;
        }

        if (! $this->hasProviderPaymentId($fresh)) {
            $this->handleRecoveredPaymentIdAnswer($fresh, $statusUpdate);

            return;
        }

        $this->applyVerdictOnly($statusUpdate);
    }

    /**
     * Apply a CheckOrder recovery answer to an attempt that was polled
     * without a PaymentId.
     *
     * The answer must prove its identity first (same provider, same
     * OrderId, non-empty PaymentId). The id is then bound under a row
     * lock with a fresh re-read — never overwriting an id another actor
     * bound meanwhile. Only after a successful or idempotent bind does a
     * FINAL bank verdict continue into the existing
     * PaymentTransitionService (amount validation, state machine and
     * event dedup all stay hers), and a refused transition is never
     * logged as a successful recovery. NEW / AUTHORIZED stop at the bind:
     * the bank merely confirmed the payment exists, so status, failure
     * fields, finished_at and non-poll metadata stay untouched and the
     * checkout block survives.
     */
    private function handleRecoveredPaymentIdAnswer(PaymentAttempt $fresh, ProviderStatusUpdate $statusUpdate): void
    {
        $answerPaymentId = $statusUpdate->providerPaymentId;

        if ($statusUpdate->provider !== $fresh->provider
            || $statusUpdate->internalOrderId !== $fresh->internal_order_id
            || $answerPaymentId === null
            || $answerPaymentId === '') {
            Log::warning('PaymentId recovery answer rejected', [
                'attempt_id' => $fresh->id,
                'answer_provider' => $statusUpdate->provider,
                'answer_order_id' => $statusUpdate->internalOrderId,
                'answer_has_payment_id' => $answerPaymentId !== null && $answerPaymentId !== '',
            ]);

            return;
        }

        $bind = $this->bindRecoveredPaymentId($fresh, $answerPaymentId);

        if ($bind === 'conflict' || $bind === 'unique') {
            // Another attempt already owns this PaymentId (a webhook bound
            // a different id, or the very same id is committed elsewhere).
            // The bind transaction has already rolled back — no transition
            // of a foreign attempt, no partial write, just diagnostics.
            Log::warning('PaymentId recovery conflict', [
                'attempt_id' => $fresh->id,
                'provider_payment_id' => $answerPaymentId,
                'outcome' => $bind,
            ]);

            return;
        }

        if ($bind !== 'bound' && $bind !== 'already') {
            return;
        }

        if (! in_array($statusUpdate->normalizedStatus, self::FINAL_VERDICTS, true)) {
            Log::info('PaymentId recovered for payment attempt', [
                'attempt_id' => $fresh->id,
                'provider_payment_id' => $answerPaymentId,
                'bank_status' => $statusUpdate->normalizedStatus->value,
            ]);

            return;
        }

        // Confirmed success or refusal — handed to the existing state
        // machine in this very run, after the id is safely bound.
        $result = $this->transitionService->transition($statusUpdate);

        if ($result['success']) {
            Log::info('PaymentId recovery resolved attempt', [
                'attempt_id' => $fresh->id,
                'provider_payment_id' => $answerPaymentId,
                'new_status' => $statusUpdate->normalizedStatus->value,
            ]);
        } else {
            Log::warning('PaymentId recovery transition failed', [
                'attempt_id' => $fresh->id,
                'error' => $result['error'] ?? null,
            ]);
        }
    }

    /**
     * Bind a recovered PaymentId under a row lock with a fresh re-read.
     *
     * Only the provider_payment_id column is written — status, failure
     * fields, finished_at and metadata (poll keys included) are owned by
     * their own writers. An id already present is never overwritten: the
     * same id is an idempotent 'already', a different one a 'conflict'.
     * The existing UNIQUE (provider, provider_payment_id) index is the
     * final word: its violation aborts the transaction and surfaces only
     * AFTER the rollback as 'unique'.
     *
     * @return string 'bound' | 'already' | 'conflict' | 'unique' | 'missing'
     */
    private function bindRecoveredPaymentId(PaymentAttempt $attempt, string $providerPaymentId): string
    {
        try {
            return DB::transaction(function () use ($attempt, $providerPaymentId) {
                $locked = PaymentAttempt::lockForUpdate()
                    ->whereKey($attempt->getKey())
                    ->first();

                if ($locked === null) {
                    return 'missing';
                }

                $current = $locked->provider_payment_id;

                if ($current !== null && $current !== '') {
                    return $current === $providerPaymentId ? 'already' : 'conflict';
                }

                $locked->update(['provider_payment_id' => $providerPaymentId]);

                return 'bound';
            });
        } catch (UniqueConstraintViolationException) {
            return 'unique';
        }
    }

    /**
     * Восстановление SBP-ссылки СУЩЕСТВУЮЩЕЙ попытки повторным GetQr.
     *
     * Никакого нового Init и никакого продления RedirectDueDate: банк
     * спрашивается только о QR уже привязанного PaymentId. Порядок:
     * свежее перечитывание строки → GetQr вне DB-транзакций → короткая
     * row-lock-транзакция с повторной проверкой условий (см.
     * storeRecoveredSbpQrLink). Вызывается под общим status-check lock'ом.
     *
     * Основание для GetQr — ТОЛЬКО фактический ответ банка NEW или
     * AUTHORIZED в этой же проверке: null, транспортная ошибка и
     * нераспознанный статус основанием не являются. Нормализованный статус
     * для этой цели не годится — NEW нормализуется в Unknown так же, как и
     * любые неизвестные статусы. Любая ошибка здесь — warning без влияния
     * на статус попытки или её PaymentId.
     */
    private function maybeRecoverSbpQrLink(PaymentAttempt $attempt, ProviderStatusUpdate $statusUpdate): void
    {
        $rawStatus = $statusUpdate->raw['Status'] ?? null;

        if (! is_string($rawStatus) || ! in_array($rawStatus, ['NEW', 'AUTHORIZED'], true)) {
            return;
        }

        $fresh = PaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->first();

        if ($fresh === null || ! $this->sbpQrRecoveryRowGates($fresh)) {
            return;
        }

        $gateway = $this->gatewayManager->getGateway($fresh->provider);

        if (! $gateway instanceof TBankPaymentGateway) {
            return;
        }

        $paymentId = (string) $fresh->provider_payment_id;

        try {
            $qr = $gateway->fetchSbpQrPayload($paymentId);
        } catch (\Throwable $e) {
            Log::warning('SBP link recovery: GetQr failed', [
                'attempt_id' => $fresh->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->storeRecoveredSbpQrLink($fresh->getKey(), $paymentId, $qr);
    }

    /**
     * Условия восстановления SBP-ссылки по строке попытки (все обязательны):
     *
     * - tbank-checkout без противоречивых данных способа: checkout_url
     *   пуст, а способ — 'sbp' либо отсутствует/null. Контракт Phase A:
     *   sbp_expires_at пишется в metadata только для sbp-попыток при
     *   создании checkout, а payment_method/sbp_payload появляются лишь
     *   в Phase C после успешного Init. Реальная попытка после ошибки
     *   Init/GetQr несёт будущий срок без способа — такой пробел
     *   допустим. Явный 'card', любое другое непустое или некорректное
     *   значение отклоняются (fail closed);
     * - попытка всё ещё в полёте (ordinary-очередь): не terminal, не
     *   reconciliation_timeout, не финальный вердикт;
     * - PaymentId привязан (уже известен или восстановлен через CheckOrder);
     * - не renewal;
     * - sbp_payload ещё отсутствует;
     * - исходный sbp_expires_at разобран и ещё в будущем. Ключ никогда не
     *   продлевается; попытки старее этой записи ключа не имеют вовсе — для
     *   них восстановление закрыто (срок/способ неизвестны).
     */
    private function sbpQrRecoveryRowGates(PaymentAttempt $fresh): bool
    {
        if ($fresh->provider !== 'tbank') {
            return false;
        }

        if (! $this->isOrdinaryQueueAttempt($fresh)) {
            return false;
        }

        if (! $this->hasProviderPaymentId($fresh)) {
            return false;
        }

        if ($this->isRenewalAttempt($fresh)) {
            return false;
        }

        $metadata = $this->attemptMetadata($fresh);

        // Контракт Phase A: способ дописывается только в Phase C, поэтому
        // отсутствующий/null ключ — это sbp-попытка, у которой Init не
        // прошёл. 'card' или мусор — чужая попытка, восстановление закрыто.
        $method = $metadata['payment_method'] ?? null;
        if ($method !== null && $method !== 'sbp') {
            return false;
        }

        $checkoutUrl = $metadata['checkout_url'] ?? null;
        if (is_string($checkoutUrl) && $checkoutUrl !== '') {
            return false;
        }

        $payload = $metadata['sbp_payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            return false;
        }

        return $this->sbpLinkDeadlineIsFuture($metadata['sbp_expires_at'] ?? null);
    }

    /**
     * Исходный абсолютный срок SBP-ссылки разобран и ещё в будущем.
     */
    private function sbpLinkDeadlineIsFuture(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $deadline = Carbon::parse($expiresAt);
        } catch (\Throwable) {
            return false;
        }

        return now()->lt($deadline);
    }

    /**
     * Сохранение восстановленного SBP-payload под row lock с повторной
     * проверкой: тот же PaymentId, допустимый статус, срок ещё в будущем,
     * payload всё ещё отсутствует, способ не изменился. Мержатся ТОЛЬКО
     * sbp_payload, payment_method (дописывается 'sbp' попытке Phase A,
     * у которой Init не успел записать способ) и request_key —
     * webhook-маркеры, poll metadata и исходный sbp_expires_at не
     * затираются. Если другой процесс успел сохранить payload раньше — его
     * ссылка остаётся нетронутой ('kept'); истёкший срок или изменившийся
     * статус во время GetQr не дают сохранить ссылку вовсе ('skipped').
     *
     * @param  array{payload: string, request_key: string|null}  $qr
     * @return string 'saved' | 'kept' | 'skipped' | 'missing'
     */
    private function storeRecoveredSbpQrLink(int|string $attemptId, string $paymentId, array $qr): string
    {
        $outcome = DB::transaction(function () use ($attemptId, $paymentId, $qr) {
            $locked = PaymentAttempt::lockForUpdate()
                ->whereKey($attemptId)
                ->first();

            if ($locked === null) {
                return 'missing';
            }

            if ($locked->provider_payment_id !== $paymentId) {
                return 'skipped';
            }

            $metadata = $this->attemptMetadata($locked);

            $existingPayload = $metadata['sbp_payload'] ?? null;
            if (is_string($existingPayload) && $existingPayload !== '') {
                // Кто-то уже сохранил ссылку этой попытки — его и оставляем
                return 'kept';
            }

            if (! $this->sbpQrRecoveryRowGates($locked)) {
                return 'skipped';
            }

            $metadata['payment_method'] = 'sbp';
            $metadata['sbp_payload'] = $qr['payload'];

            if ($qr['request_key'] !== null) {
                $metadata['request_key'] = $qr['request_key'];
            }

            $locked->update(['metadata' => $metadata]);

            return 'saved';
        });

        if ($outcome === 'saved') {
            Log::info('SBP link recovered for payment attempt', [
                'attempt_id' => $attemptId,
            ]);
        }

        return $outcome;
    }

    /**
     * Whether the bank may still be asked about this attempt at all: either
     * the ordinary queue owns it (still in flight) or the timeout queue does
     * (age-released, but with a provider payment id to ask about). A
     * resolved attempt — Succeeded, Refunded, a confirmed refusal — is never
     * re-checked, whatever snapshot the caller still holds.
     */
    private function isStatusCheckEligible(PaymentAttempt $attempt): bool
    {
        return $this->isOrdinaryQueueAttempt($attempt)
            || $this->isTimeoutQueueAttempt($attempt);
    }

    /**
     * A row of the ordinary queue: still in flight at the bank.
     */
    private function isOrdinaryQueueAttempt(PaymentAttempt $attempt): bool
    {
        return in_array($attempt->status, [
            PaymentAttemptStatus::Created,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        ], true);
    }

    /**
     * A row of the timeout queue: age-released to failed_terminal with the
     * local reconciliation_timeout category, still askable at the bank —
     * either through its provider payment id (GetState) or, for a tbank
     * checkout attempt that lost its Init response and never got an id,
     * through CheckOrder recovery.
     */
    private function isTimeoutQueueAttempt(PaymentAttempt $attempt): bool
    {
        if ($attempt->status !== PaymentAttemptStatus::FailedTerminal
            || $attempt->failure_category !== 'reconciliation_timeout') {
            return false;
        }

        return $this->hasProviderPaymentId($attempt)
            || $this->isPaymentIdRecoveryCandidate($attempt);
    }

    /**
     * Whether the attempt still waits for its provider payment id at all.
     */
    private function hasProviderPaymentId(PaymentAttempt $attempt): bool
    {
        return $attempt->provider_payment_id !== null
            && $attempt->provider_payment_id !== '';
    }

    /**
     * Whether this attempt may recover its PaymentId via CheckOrder:
     * tbank, still NULL or '' in provider_payment_id, and NOT a renewal —
     * a renewal attempt without an id is owned by RenewalPaymentExecutor
     * (its Phase R CheckOrder for the same OrderId), so reconciliation
     * must never fire a second concurrent lookup for it.
     */
    private function isPaymentIdRecoveryCandidate(PaymentAttempt $attempt): bool
    {
        if ($this->hasProviderPaymentId($attempt)) {
            return false;
        }

        if ($attempt->provider !== 'tbank') {
            return false;
        }

        return ! $this->isRenewalAttempt($attempt);
    }

    /**
     * A renewal attempt, per the real marker the renewal chain writes:
     * metadata.renewal === true — the very flag the executor guard, the
     * grace window and the insufficient-funds flow all key on. The executor
     * refuses to run an attempt without it, so an attempt that lacks it is
     * never double-owned even if its cycle origin says Renewal.
     */
    private function isRenewalAttempt(PaymentAttempt $attempt): bool
    {
        return ($this->attemptMetadata($attempt)['renewal'] ?? null) === true;
    }

    /**
     * The one provider call for the fresh row: GetState when a PaymentId
     * is already bound, CheckOrder recovery otherwise. Returns null for
     * rows that must not be asked about at all (non-tbank without an id,
     * renewal without an id, foreign gateway driver) — no HTTP happens.
     *
     * One exception switches the known-id path to CheckOrder: a tbank
     * checkout attempt whose stored card_expires_at has already passed.
     * The bank's confirmed deadline option answers with DEADLINE_EXPIRED
     * for an unpaid expired link and keeps the real successful status for
     * an already-paid one — GetState is NOT expected to report the expiry,
     * so the deadline case must ask by OrderId. The identity of a known-id
     * answer is verified (provider, OrderId, PaymentId) before anyone can
     * act on it: a foreign answer is rejected, never applied, never bound.
     * Attempts without a deadline, SBP rows, renewals and mock keep the
     * ordinary GetState path untouched.
     */
    private function fetchProviderStatus(PaymentAttempt $fresh): ?ProviderStatusUpdate
    {
        if ($this->hasProviderPaymentId($fresh)) {
            if ($this->cardDeadlineHasPassed($fresh)) {
                return $this->fetchExpiredCardStatus($fresh);
            }

            return $this->gatewayManager
                ->getGateway($fresh->provider)
                ->getPaymentStatus($fresh->provider_payment_id, $fresh->internal_order_id);
        }

        return $this->recoverPaymentIdViaCheckOrder($fresh);
    }

    /**
     * CheckOrder for a card attempt past its stored card_expires_at while
     * already carrying a known PaymentId. Asked by OrderId; the answer must
     * prove it belongs to THIS attempt (same provider, same OrderId, same
     * PaymentId) or it is rejected as null — a foreign PaymentId is never
     * applied here and never rebound elsewhere. Null / 335 answers simply
     * keep the attempt waiting; nothing in here ever treats an ambiguous
     * or failed lookup as a refusal.
     */
    private function fetchExpiredCardStatus(PaymentAttempt $fresh): ?ProviderStatusUpdate
    {
        $gateway = $this->gatewayManager->getGateway($fresh->provider);

        if (! $gateway instanceof TBankPaymentGateway) {
            return null;
        }

        $statusUpdate = $gateway->findPaymentByOrderId($fresh->internal_order_id);

        if ($statusUpdate === null) {
            return null;
        }

        if ($statusUpdate->provider !== $fresh->provider
            || $statusUpdate->internalOrderId !== $fresh->internal_order_id
            || $statusUpdate->providerPaymentId !== $fresh->provider_payment_id) {
            Log::warning('Expired-card CheckOrder answer rejected: identity mismatch', [
                'attempt_id' => $fresh->id,
                'answer_provider' => $statusUpdate->provider,
                'answer_order_id' => $statusUpdate->internalOrderId,
                'answer_payment_id' => $statusUpdate->providerPaymentId,
                'attempt_order_id' => $fresh->internal_order_id,
                'attempt_payment_id' => $fresh->provider_payment_id,
            ]);

            return null;
        }

        return $statusUpdate;
    }

    /**
     * The attempt carries a trustworthy card deadline that has already
     * passed — the safe predicate for switching checks to CheckOrder:
     *
     * - provider tbank (the CheckOrder contract owner; mock and anything
     *   else keep their own path);
     * - NOT a renewal — renewals are owned by the executor chain;
     * - no contradictory SBP data: method 'sbp', a non-empty sbp_payload
     *   or a separate sbp_expires_at make the card context ambiguous and
     *   the row fails closed to its ordinary path (never a refusal);
     * - payment_method may be 'card' OR ABSENT — a lost Init response
     *   leaves Phase A's card_expires_at without the Phase C marker, and
     *   that must not block recovery;
     * - card_expires_at itself parses and is no longer in the future.
     *   Invalid / malformed values simply keep the old path — they are
     *   never evidence of a decline.
     */
    private function cardDeadlineHasPassed(PaymentAttempt $attempt): bool
    {
        if ($attempt->provider !== 'tbank') {
            return false;
        }

        if ($this->isRenewalAttempt($attempt)) {
            return false;
        }

        $metadata = $this->attemptMetadata($attempt);

        $method = $metadata['payment_method'] ?? null;
        if ($method !== null && $method !== 'card') {
            return false;
        }

        $sbpPayload = $metadata['sbp_payload'] ?? null;
        if (is_string($sbpPayload) && $sbpPayload !== '') {
            return false;
        }

        $sbpExpiresAt = $metadata['sbp_expires_at'] ?? null;
        if (is_string($sbpExpiresAt) && $sbpExpiresAt !== '') {
            return false;
        }

        return $this->deadlineHasPassed($metadata['card_expires_at'] ?? null);
    }

    /**
     * Абсолютный срок разобран и уже наступил.
     */
    private function deadlineHasPassed(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $deadline = Carbon::parse($expiresAt);
        } catch (\Throwable) {
            return false;
        }

        return now()->gte($deadline);
    }

    /**
     * CheckOrder recovery for a tbank checkout attempt that never received
     * its PaymentId (lost Init response). ErrorCode 335 ("no payment yet")
     * comes back as null — the attempt keeps waiting, the local timeout
     * and its checkout block stay untouched; every ambiguous or failing
     * answer throws from the gateway and is counted as an error by the
     * caller. Nothing here ever Inits, reclassifies or Cancels.
     */
    private function recoverPaymentIdViaCheckOrder(PaymentAttempt $fresh): ?ProviderStatusUpdate
    {
        if (! $this->isPaymentIdRecoveryCandidate($fresh)) {
            return null;
        }

        $gateway = $this->gatewayManager->getGateway($fresh->provider);

        if (! $gateway instanceof TBankPaymentGateway) {
            return null;
        }

        return $gateway->findPaymentByOrderId($fresh->internal_order_id);
    }

    /**
     * Reconcile a single payment attempt of the ordinary queue.
     *
     * The batch snapshot only decides whether the row is worth a closer
     * look — age release, the post-expiry bypass and the backoff window are
     * cheap pre-checks. The GetState itself goes through runStatusCheck,
     * which re-reads the row and repeats those conditions under the shared
     * lock, so a snapshot that has aged out meanwhile fires no HTTP at all.
     *
     * @param  array<int, int>  $backoff
     */
    private function reconcileAttempt(
        PaymentAttempt $attempt,
        array $backoff,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): void {
        // Check age release
        if ($this->shouldAgeRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId)) {
            $this->ageRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId);

            return;
        }

        // ── Post-expiry one-shot ──
        // An SBP attempt whose link has already expired gets ONE timely
        // GetState through the shared lock+cooldown, whatever the old
        // backoff says — the bank's answer arrives while the user is still
        // waiting, even with the browser closed. poll_count is never reset:
        // after this poll the ordinary backoff continues from the advanced
        // metadata, and the consumed one-shot marker keeps every following
        // pass out of the bank. A skip (lock held / cooldown still running)
        // reaches no HTTP and therefore consumes no marker.
        if ($this->isSbpExpiryBypassDue($attempt)) {
            $this->runStatusCheck(
                $attempt,
                due: fn (PaymentAttempt $fresh): bool => $this->isOrdinaryQueueAttempt($fresh)
                    && $this->isSbpExpiryBypassDue($fresh)
                    && $this->statusCheckCooldownExpired($fresh),
                handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                    $this->applyVerdictOnly($statusUpdate);
                },
                advancePollMetadata: true,
                consumeSbpExpiry: true,
            );

            return;
        }

        // ── Card expiry one-shot ──
        // A card attempt whose link deadline has passed gets ONE timely
        // CheckOrder (GetState does not report RedirectDueDate expiry) through
        // the same shared lock+cooldown, whatever the old backoff says — the
        // bank's verdict arrives while the user is still waiting. Works with
        // or without a known PaymentId (CheckOrder asks by OrderId). poll_count
        // is never reset: after this check the ordinary backoff continues from
        // the advanced metadata, and the consumed card_expiry_polled_at marker
        // keeps every following pass out of the bank. A skip (lock held /
        // cooldown still running) reaches no HTTP and consumes no marker.
        if ($this->isCardExpiryBypassDue($attempt)) {
            $this->runStatusCheck(
                $attempt,
                due: fn (PaymentAttempt $fresh): bool => $this->isOrdinaryQueueAttempt($fresh)
                    && $this->isCardExpiryBypassDue($fresh)
                    && $this->statusCheckCooldownExpired($fresh),
                handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                    $this->applyCardExpiryVerdict($fresh, $statusUpdate);
                },
                advancePollMetadata: true,
                consumeCardExpiry: true,
            );

            return;
        }

        // Check backoff - skip if not yet time
        if (! $this->isPollDue($attempt, $backoff)) {
            return; // Not yet time to poll
        }

        // Resolve gateway
        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            Log::warning('Unknown gateway for reconciliation', [
                'attempt_id' => $attempt->id,
                'provider' => $attempt->provider,
            ]);

            return;
        }

        $this->runStatusCheck(
            $attempt,
            due: fn (PaymentAttempt $fresh): bool => $this->isOrdinaryQueueAttempt($fresh)
                && $this->statusCheckCooldownExpired($fresh)
                && $this->isPollDue($fresh, $backoff),
            handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                if ($statusUpdate === null) {
                    // No answer: the backoff above already advanced, the
                    // attempt keeps waiting exactly as it was.
                    return;
                }

                // This answer was asked for WITHOUT a PaymentId — a CheckOrder
                // recovery. Bind the id first; only a final verdict continues.
                if (! $this->hasProviderPaymentId($fresh)) {
                    $this->handleRecoveredPaymentIdAnswer($fresh, $statusUpdate);

                    return;
                }

                // T-Bank GetState for an in-flight recurring payment (Status=NEW)
                // normalizes as Unknown while the local attempt is Processing.
                // processing -> unknown is not an allowed transition, so skip the
                // state machine entirely: keep Processing, the poll metadata is
                // already advanced, and create no provider event.
                if ($fresh->status === PaymentAttemptStatus::Processing
                    && $statusUpdate->normalizedStatus === PaymentAttemptStatus::Unknown) {
                    return;
                }

                // Provider returned a status - transition
                $result = $this->transitionService->transition($statusUpdate);

                if ($result['success']) {
                    Log::info('Reconciliation resolved attempt', [
                        'attempt_id' => $fresh->id,
                        'new_status' => $statusUpdate->normalizedStatus->value,
                    ]);
                } else {
                    Log::warning('Reconciliation transition failed', [
                        'attempt_id' => $fresh->id,
                        'error' => $result['error'],
                    ]);
                }
            },
            advancePollMetadata: true,
            // As before: the transport failure is handed back to reconcile(),
            // which counts it in errors and logs it — the backoff has already
            // moved on by then.
            rethrow: true,
            // Обычная очередь — единственный автоматический путь, где SBP
            // ещё в полёте и восстановление ссылки уместно; post-expiry
            // bypass (срок уже истёк) и timeout-очередь (terminal) — нет.
            recoverSbpLink: true,
        );
    }

    /**
     * Attempt metadata as an array.
     *
     * The 'array' cast is invisible to static analysis here (larastan falls
     * back to the json column type), so the shape is declared once at this
     * boundary instead of guessed at every offset read.
     *
     * @return array<string, mixed>
     */
    private function attemptMetadata(PaymentAttempt $attempt): array
    {
        $metadata = $attempt->metadata ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Whether the per-attempt status-check cooldown has elapsed.
     *
     * Missing or malformed metadata → allowed (fail-open, same as the
     * backoff parser). The cooldown is measured from the last ACTUAL
     * GetState of this attempt, manual or background alike.
     */
    private function statusCheckCooldownExpired(PaymentAttempt $attempt): bool
    {
        $cooldownSeconds = (int) config(
            'billing.reconciliation.status_check_cooldown_seconds',
            60,
        );

        $lastCheckedAt = $this->parseLastPolledAt(
            $this->attemptMetadata($attempt)['last_status_check_at'] ?? null,
        );

        if ($lastCheckedAt === null) {
            return true;
        }

        return now()->gte($lastCheckedAt->addSeconds($cooldownSeconds));
    }

    /**
     * The post-expiry bypass is a ONE-SHOT per attempt: due once the stored
     * absolute sbp_expires_at has passed and the consuming marker
     * (sbp_expiry_polled_at) is still absent. In-flight SBP attempts with a
     * provider payment id only — a released attempt lives in the timeout
     * queue, and an attempt without a provider id has nothing to ask the
     * bank about. Re-checked on the fresh row under the lock, so a marker
     * consumed meanwhile ends the bypass for this pass.
     */
    private function isSbpExpiryBypassDue(PaymentAttempt $attempt): bool
    {
        $metadata = $this->attemptMetadata($attempt);

        if (($metadata['payment_method'] ?? null) !== 'sbp') {
            return false;
        }

        if ($attempt->provider_payment_id === null || $attempt->provider_payment_id === '') {
            return false;
        }

        if (array_key_exists('sbp_expiry_polled_at', $metadata)) {
            // Already consumed — the ordinary backoff rules from here on
            return false;
        }

        $expiresAt = $metadata['sbp_expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $deadline = Carbon::parse($expiresAt);
        } catch (\Throwable) {
            return false;
        }

        return now()->gte($deadline);
    }

    /**
     * The card post-expiry bypass is a ONE-SHOT per attempt: due once the
     * stored absolute card_expires_at has passed and the consuming marker
     * (card_expiry_polled_at) is still absent. In contrast to the SBP
     * bypass it does NOT require a provider payment id — CheckOrder asks
     * by OrderId, so a lost-Init attempt can be recovered too. Re-checked
     * on the fresh row under the lock, so a marker consumed meanwhile ends
     * the bypass for this pass; a skip (lock/cooldown) reaches no HTTP and
     * consumes nothing. After the one-shot the ordinary backoff rules,
     * advanced by this very check, keep the bank quiet.
     */
    private function isCardExpiryBypassDue(PaymentAttempt $attempt): bool
    {
        $metadata = $this->attemptMetadata($attempt);

        if (array_key_exists('card_expiry_polled_at', $metadata)) {
            // Already consumed — the ordinary backoff rules from here on
            return false;
        }

        return $this->cardDeadlineHasPassed($attempt);
    }

    /**
     * GetState for an attempt that was already age-released to
     * failed_terminal + reconciliation_timeout and can still be asked
     * about: with a provider payment id, or via CheckOrder recovery when
     * a tbank checkout attempt never received one.
     *
     * No age-release here — it has already run and must not run again — and
     * the answer handling is its own: only a final bank verdict leaves the
     * local timeout, everything else keeps it and its checkout block. The
     * call itself goes through runStatusCheck: backoff and row state are
     * re-checked under the shared lock, the HTTP stays outside any
     * transaction and the poll metadata is merged (one increment per actual
     * call, exception included) before the lock is released.
     *
     * @param  array<int, int>  $backoff
     */
    private function reconcileTimeoutAttempt(
        PaymentAttempt $attempt,
        array $backoff,
    ): void {
        // ── Card expiry one-shot for an age-released row ──
        // The release did NOT answer the bank's expiry question — a card
        // attempt with a trustworthy passed deadline still gets its ONE
        // timely CheckOrder regardless of the old backoff (selection lets
        // such rows into this queue, see selectUnresolvedTimeouts). Same
        // lock+cooldown, same final-only verdict handling; a non-final
        // answer keeps the local timeout, its checkout block and the
        // undefined_outcome flag untouched.
        if ($this->isCardExpiryBypassDue($attempt)) {
            $this->runStatusCheck(
                $attempt,
                due: fn (PaymentAttempt $fresh): bool => $this->isTimeoutQueueAttempt($fresh)
                    && $this->isCardExpiryBypassDue($fresh)
                    && $this->statusCheckCooldownExpired($fresh),
                handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                    $this->applyCardExpiryVerdict($fresh, $statusUpdate);
                },
                advancePollMetadata: true,
                consumeCardExpiry: true,
            );

            return;
        }

        // Same backoff as the ordinary queue — a row still waiting out its
        // window costs no HTTP at all. Selection already applied this when
        // the batch was filled; it is re-checked here so a stale snapshot
        // can never fire an early GetState.
        if (! $this->isPollDue($attempt, $backoff)) {
            return; // Not yet time to poll
        }

        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            Log::warning('Unknown gateway for reconciliation', [
                'attempt_id' => $attempt->id,
                'provider' => $attempt->provider,
            ]);

            return;
        }

        $this->runStatusCheck(
            $attempt,
            due: fn (PaymentAttempt $fresh): bool => $this->isTimeoutQueueAttempt($fresh)
                && $this->statusCheckCooldownExpired($fresh)
                && $this->isPollDue($fresh, $backoff),
            handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                if ($statusUpdate === null) {
                    // No verdict: the local timeout, its checkout block and
                    // the undefined_outcome flag all stay untouched.
                    return;
                }

                // Released WITHOUT a PaymentId — this answer came from a
                // CheckOrder recovery. Bind the id first; a NEW/AUTHORIZED
                // answer stops there (the timeout and its block survive,
                // the next pass may GetState the now-bound id).
                if (! $this->hasProviderPaymentId($fresh)) {
                    $this->handleRecoveredPaymentIdAnswer($fresh, $statusUpdate);

                    return;
                }

                if (! in_array($statusUpdate->normalizedStatus, self::FINAL_VERDICTS, true)) {
                    // Still in flight at the bank (NEW / AUTHORIZED): a
                    // non-terminal status is never sent to transition() —
                    // the attempt keeps its timeout until a real verdict
                    // arrives.
                    return;
                }

                // Confirmed success or refusal — the existing transition
                // path, which resolves the local timeout in place under its
                // own lock/validation.
                $result = $this->transitionService->transition($statusUpdate);

                if ($result['success']) {
                    Log::info('Reconciliation resolved attempt', [
                        'attempt_id' => $fresh->id,
                        'new_status' => $statusUpdate->normalizedStatus->value,
                    ]);
                } else {
                    Log::warning('Reconciliation transition failed', [
                        'attempt_id' => $fresh->id,
                        'error' => $result['error'] ?? null,
                    ]);
                }
            },
            advancePollMetadata: true,
            // The failure stays visible to the caller (errors counter) and
            // the backoff has already moved on before it is handed back.
            rethrow: true,
        );
    }

    /**
     * Attempts age-released to failed_terminal + failure_category
     * reconciliation_timeout that the bank may still be asked about: with a
     * provider payment id (GetState), or a tbank checkout attempt without
     * one (CheckOrder recovery — released attempts without an id join this
     * queue too). Rows that are neither (mock, renewal without an id) are
     * passed over in the walk without taking a slot.
     *
     * Fair queue: rows are served least-recently-polled first (never polled
     * first). Readiness is applied WHILE the batch is filled, not after it:
     * a row still waiting out its backoff is passed over without taking one
     * of the $limit slots, so an older row with a long window can no longer
     * crowd out a fresher row that is due right now. Skipped rows are never
     * written to — their poll metadata stays exactly as it was.
     *
     * The queue is walked one page at a time (max($limit, 100) candidates
     * in memory, never a full-queue get()) and the walk stops at $limit due
     * rows. Bounded by its own limit — the ordinary batch above keeps its
     * full batch_size.
     *
     * A card attempt whose one-shot deadline bypass is due is selected even
     * inside its backoff window — the bypass is the mechanism that should
     * fire for it (reconcileTimeoutAttempt routes it there); an already
     * consumed marker falls back to plain readiness as before.
     *
     * @param  array<int, int>  $backoff
     * @return Collection<int, PaymentAttempt>
     */
    private function selectUnresolvedTimeouts(int $limit, array $backoff): Collection
    {
        $due = PaymentAttempt::where('status', PaymentAttemptStatus::FailedTerminal)
            ->where('failure_category', 'reconciliation_timeout')
            ->orderByRaw("metadata->>'last_polled_at' ASC NULLS FIRST")
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(max($limit, 100))
            ->filter(fn (PaymentAttempt $attempt): bool => $this->isPollableTimeoutRow($attempt)
                && ($this->isPollDue($attempt, $backoff)
                    || $this->isCardExpiryBypassDue($attempt)))
            ->take($limit)
            ->values();

        return new Collection($due->all());
    }

    /**
     * A released row the bank can still be asked about: an id for GetState,
     * or a CheckOrder recovery candidate. Everything else (mock, renewal
     * without an id) never takes a timeout-queue slot.
     */
    private function isPollableTimeoutRow(PaymentAttempt $attempt): bool
    {
        return $this->hasProviderPaymentId($attempt)
            || $this->isPaymentIdRecoveryCandidate($attempt);
    }

    /**
     * Whether an attempt of either automatic queue may be polled right now.
     *
     * A row never polled, or one whose poll metadata cannot be read, is due
     * (the same fail-open the poll itself relies on); otherwise the existing
     * backoff indexed by poll_count must have elapsed since last_polled_at.
     * Evaluated on the batch snapshot as a cheap pre-check and again on the
     * fresh row under the shared lock, so a row polled in the meantime never
     * fires an early GetState.
     *
     * @param  array<int, int>  $backoff
     */
    private function isPollDue(PaymentAttempt $attempt, array $backoff): bool
    {
        $metadata = $this->attemptMetadata($attempt);
        $pollCount = $metadata['poll_count'] ?? 0;
        $lastPolledAt = $this->parseLastPolledAt($metadata['last_polled_at'] ?? null);

        if ($pollCount > 0 && $lastPolledAt !== null) {
            $backoffSeconds = $backoff[min($pollCount - 1, count($backoff) - 1)];

            return now()->gte($lastPolledAt->addSeconds($backoffSeconds));
        }

        return true;
    }

    /**
     * Parse metadata last_polled_at (ISO8601 string) into Carbon.
     *
     * Returns null for missing or malformed values: backoff then cannot be
     * evaluated, so the current poll is allowed instead of failing. The
     * original metadata value is never mutated — a fresh instance is parsed.
     */
    private function parseLastPolledAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if attempt should be age-released.
     */
    private function shouldAgeRelease(
        PaymentAttempt $attempt,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): bool {
        $maxAge = $attempt->provider_payment_id !== null
            ? $maxAgeWithProviderId
            : $maxAgeWithoutProviderId;

        return $attempt->created_at->addSeconds($maxAge)->isPast();
    }

    /**
     * Age-release attempt to failed_terminal.
     *
     * The batch model may be stale by the time this runs (a webhook can
     * confirm the payment in between), so the attempt is re-read under a
     * row lock and the release decision is re-taken against the fresh
     * status and age. Nothing is written when the outcome is already
     * decided — Succeeded attempts, a Paid cycle and an already extended
     * subscription are never overwritten. Attempt, cycle and subscription
     * changes commit atomically in one transaction.
     *
     * Stays a local release: PaymentTransitionService::transition() would
     * claim a provider event and therefore fabricate a webhook, which the
     * provider never sent. The lock order (attempt → cycle) matches the
     * transition path, so the two cannot interleave into a lost update.
     */
    private function ageRelease(
        PaymentAttempt $attempt,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): void {
        DB::transaction(function () use ($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId) {
            $fresh = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($fresh === null
                || ! $this->isAgeReleaseEligible($fresh, $maxAgeWithProviderId, $maxAgeWithoutProviderId)) {
                return;
            }

            $cycle = $fresh->billing_cycle_id === null
                ? null
                : BillingCycle::lockForUpdate()->whereKey($fresh->billing_cycle_id)->first();

            if ($cycle !== null && $cycle->status === BillingCycleStatus::Paid) {
                // The renewal already succeeded — never downgrade Paid and
                // never open a technical grace on a renewed subscription.
                return;
            }

            $fresh->update([
                'status' => PaymentAttemptStatus::FailedTerminal,
                'failure_category' => 'reconciliation_timeout',
                'finished_at' => now(),
            ]);

            if ($cycle !== null && $cycle->status !== BillingCycleStatus::Failed) {
                $cycle->update(['status' => BillingCycleStatus::Failed]);
            }

            // Same timeout + bounded technical grace rules as before, but
            // applied to the locked row from this transaction.
            $this->transitionService->applyTechnicalRenewalGrace($fresh);

            Log::info('Age-released payment attempt', [
                'attempt_id' => $fresh->id,
                'provider_payment_id' => $fresh->provider_payment_id,
                'age_hours' => $fresh->created_at->diffInHours(now()),
            ]);
        });
    }

    /**
     * Re-evaluate the age-release decision against the fresh row: the
     * attempt must still be in a batch-eligible status and already older
     * than the age limit that applies to its current provider_payment_id.
     */
    private function isAgeReleaseEligible(
        PaymentAttempt $attempt,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): bool {
        if (! $this->isOrdinaryQueueAttempt($attempt)) {
            return false;
        }

        return $this->shouldAgeRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId);
    }
}
