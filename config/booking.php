<?php

return [

    'token_ttl' => env('BOOKING_TOKEN_TTL', 300),

    'draft_ttl' => env('BOOKING_DRAFT_TTL', 900),

    'default_timezone' => env('BOOKING_DEFAULT_TIMEZONE', 'Europe/Moscow'),

    'cleanup_draft_threshold' => env('BOOKING_CLEANUP_DRAFT_THRESHOLD', 15),

    'free_monthly_limit' => env('BOOKING_FREE_MONTHLY_LIMIT', 30),

];
