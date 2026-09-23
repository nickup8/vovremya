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

];
