<?php

return [

    'token_ttl' => env('BOOKING_TOKEN_TTL', 300),

    'draft_ttl' => env('BOOKING_DRAFT_TTL', 900),

    'initdata_ttl' => (int) env('BOOKING_INITDATA_TTL', 3600),

    'default_timezone' => env('BOOKING_DEFAULT_TIMEZONE', 'Europe/Moscow'),

    'cleanup_draft_threshold' => env('BOOKING_CLEANUP_DRAFT_THRESHOLD', 15),

    'cleanup_pending_subscription_hours' => env('BILLING_CLEANUP_PENDING_HOURS', 2),

    'free_monthly_limit' => env('BOOKING_FREE_MONTHLY_LIMIT', 30),

    /*
     * Marketing attribution window (last-touch) в днях.
     * Валидный переход по tracking-ссылке фиксирует источник на этот срок.
     * Прямой заход не сбрасывает и не продлевает окно.
     */
    'attribution_window_days' => (int) env('BOOKING_ATTRIBUTION_WINDOW_DAYS', 7),

];
