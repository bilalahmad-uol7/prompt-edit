<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    |
    | Use one API key per environment. Each key has its own webhook URL,
    | signing secret and usage report, so the numbers of the deployed app
    | can be reconciled exactly against GET /v1/usage.
    |
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),

    'connect_timeout' => (int) env('PIXELFORGE_CONNECT_TIMEOUT', 5),

    'webhook' => [
        'secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        // Signatures older than this many seconds are rejected to stop replays.
        'tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
    ],

    'submission' => [
        // Give up on getting a generation accepted after this many minutes.
        'give_up_after_minutes' => (int) env('PIXELFORGE_SUBMIT_GIVE_UP_AFTER', 15),

        // Pixelforge allows 60 requests per minute per account; stay a little below it.
        'requests_per_minute' => (int) env('PIXELFORGE_REQUESTS_PER_MINUTE', 50),
    ],

    'reconcile' => [
        // In-flight generations without news for this long are polled (webhooks may be lost).
        'stale_after_seconds' => (int) env('PIXELFORGE_RECONCILE_STALE_AFTER', 90),
    ],

    'images' => [
        // Pixelforge image URLs expire 24h after completion, so we keep our own copy.
        'disk' => env('GENERATION_IMAGE_DISK', 'public'),
    ],

];
