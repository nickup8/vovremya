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
        // Future: 'tbank' => [
        //     'driver' => 'tbank',
        //     'shop_id' => env('TBANK_SHOP_ID'),
        //     'secret_key' => env('TBANK_SECRET_KEY'),
        // ],
    ],

    /*
     * Reconciliation settings for unknown payment attempts.
     */
    'reconciliation' => [
        'batch_size' => 50,
        'fresh_grace_seconds' => 60,
        'backoff' => [60, 120, 300, 900, 1800, 3600],
        'max_age_with_provider_id' => 86400, // 24 hours
        'max_age_without_provider_id' => 1800, // 30 minutes
    ],

];
