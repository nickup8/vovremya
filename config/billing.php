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
        ],
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
     * Renewal settings.
     */
    'renewal' => [
        // Technical grace window (days) after a renewal attempt ends as
        // failed_terminal with failure_category=reconciliation_timeout.
        'technical_grace_days' => (int) env('BILLING_RENEWAL_TECHNICAL_GRACE_DAYS', 3),
    ],

];
