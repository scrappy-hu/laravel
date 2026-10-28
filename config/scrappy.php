<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | Bearer token for api.scrappy.hu. Generate one at
    | https://scrappy.hu/dashboard/api-keys. Never check this into source
    | control — load from .env (SCRAPPY_API_KEY).
    |
    */
    'api_key' => env('SCRAPPY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Hard ceiling on the Guzzle request — applies per call. Doesn't affect
    | the scrape job's own timeout (configured per-job via options.timeout_seconds
    | when creating).
    |
    */
    'timeout' => (int) env('SCRAPPY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Your API key's webhook signing secret. Create it once in the dashboard
    | (https://scrappy.hu/dashboard/api-keys → Webhook → key-level secret):
    | every job of the key is then signed with it. Required by
    | Scrappy::webhooks()->verify() and the Route::scrappyWebhook() receiver.
    |
    'webhook_secret' => env('SCRAPPY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Replay Window
    |--------------------------------------------------------------------------
    |
    | How old a webhook signature timestamp may be (in seconds) before
    | verify() rejects it. Defends against captured-and-replayed payloads.
    | 5 minutes matches the Scrappy server's emit window.
    |
    */
    'replay_window_seconds' => 300,

];
