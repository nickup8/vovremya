<?php

return [

    /*
     * Legacy mock webhook secret.
     *
     * When this is null or empty, the legacy /webhooks/payment endpoint
     * rejects every incoming request (fail-closed).
     *
     * Set a strong random value in production .env — never commit real secrets.
     */
    'legacy_mock_webhook_secret' => env('LEGACY_MOCK_WEBHOOK_SECRET'),

    /*
     * Enable Core entitlement reader for production parity checks.
     *
     * When false (default), only the legacy Subscription model
     * is used for runtime entitlement decisions.
     *
     * Flip to true after parity verification passes.
     */
    'core_entitlement' => env('BILLING_CORE_ENTITLEMENT', false),

    /*
     * Version of the auto-renewal terms the user explicitly consents to.
     *
     * Distinct from pdn_consent_version / legal.version.
     */
    'recurring_terms_version' => '2026-10-02',

    /*
     * Default payment gateway for checkout.
     */
    'default_gateway' => env('BILLING_DEFAULT_GATEWAY', 'mock'),

    /*
     * Payment gateway configurations.
     */
    'gateways' => [
        'mock' => [
            'driver' => 'mock',
        ],
        'tbank' => [
            'driver' => 'tbank',
            'terminal_key' => env('TBANK_TERMINAL_KEY'),
            'password' => env('TBANK_PASSWORD'),
            'base_url' => env('TBANK_BASE_URL', 'https://securepay.tinkoff.ru'),
            // Explicit payment environment for T-Bank attempts: 'demo' or
            // 'live' only. Snapshot of this value is stored on every new
            // tbank PaymentAttempt (payment_attempts.payment_env); a later
            // config change never rewrites existing attempts. Any other
            // value is treated as 'demo' — never as live. Never inferred
            // from TerminalKey, base URL, APP_ENV or the provider name.
            'environment' => env('TBANK_ENVIRONMENT', 'demo'),
        ],
    ],

    /*
     * Manual NPD receipts («Моё налоговое дело»).
     *
     * seller_inn — ИНН продавца (the ИРСИ operator). Required to attach
     * an lknpd.nalog.ru receipt link: the sellerInn path segment of the
     * URL must match this value exactly. Empty or malformed (not 10/12
     * digits) — attaching a receipt link is refused with a clear error.
     */
    'receipts' => [
        'seller_inn' => env('BILLING_RECEIPTS_SELLER_INN'),
    ],

    /*
     * Reconciliation settings for unknown payment attempts.
     */
    'reconciliation' => [
        'batch_size' => 50,
        // Separate, bounded queue for attempts already age-released to
        // failed_terminal + reconciliation_timeout that still await a
        // provider verdict. Own budget, so it never displaces the ordinary
        // in-flight batch above.
        'timeout_batch_size' => 10,
        'fresh_grace_seconds' => 60,
        'backoff' => [60, 120, 300, 900, 1800, 3600],
        'max_age_with_provider_id' => 86400, // 24 hours
        'max_age_without_provider_id' => 1800, // 30 minutes
        // Per-attempt cooldown shared by the manual POST check and the
        // post-expiry reconciliation bypass: at most one GetState per
        // attempt per window, no matter how many clicks/tabs/cron runs.
        'status_check_cooldown_seconds' => 60,
    ],

    /*
     * SBP checkout link lifetime.
     *
     * Absolute RedirectDueDate for a NEW SBP attempt (T-Bank allows
     * 1 minute … 90 days). Computed once on the server in Phase A,
     * before any provider call — reuse never extends it.
     */
    'sbp_redirect_ttl_minutes' => (int) env('BILLING_SBP_REDIRECT_TTL_MINUTES', 15),

    /*
     * Card checkout link lifetime.
     *
     * Absolute RedirectDueDate for a NEW card attempt (T-Bank allows
     * 1 minute … 90 days). Computed once on the server in Phase A, before
     * any provider call — reuse never extends it. The configured value is
     * clamped into that allowed range so a bad env value can never put
     * RedirectDueDate in the past or beyond the provider limit.
     */
    'card_redirect_ttl_minutes' => min(129600, max(1, (int) env('BILLING_CARD_REDIRECT_TTL_MINUTES', 30))),

    /*
     * Renewal settings.
     */
    'renewal' => [
        // Technical grace window (days) after a renewal attempt ends as
        // failed_terminal with failure_category=reconciliation_timeout.
        'technical_grace_days' => (int) env('BILLING_RENEWAL_TECHNICAL_GRACE_DAYS', 3),
    ],

];
