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
    | Default Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Used by Scrappy::webhooks()->verify() when no explicit secret is passed.
    | Each job gets a fresh per-job secret returned by POST /v1/jobs — store
    | that in your database and pass to verify() at receive time. This config
    | value is only useful for /v1/webhooks/test calls or if you choose to
    | reuse a single secret across all jobs (not recommended).
    |
    */
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
