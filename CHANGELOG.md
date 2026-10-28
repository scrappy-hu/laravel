# Changelog

## 0.2.0 — 2026-10-28

Breaking: webhooks are verified with the API key's own signing secret.

- `Route::scrappyWebhook($uri)` registers a ready webhook receiver: it verifies the signature (401 otherwise), answers 200 and dispatches `Scrappy\Events\JobCompleted`, `JobFailed` or `WebhookTestReceived` with a typed `WebhookEvent`.
- `Scrappy::webhooks()->verify($body, $header)` uses `SCRAPPY_WEBHOOK_SECRET` (the key-level secret from the dashboard) and throws a `config_error` without one; `parse()` returns a `WebhookEvent`. `test()` takes an optional URL (default: the key's webhook URL).
- `Scrappy::jobs()->createMany()` submits through `POST /v1/jobs/batch`, 100 per request, with per-chunk idempotency keys.
- `JobCreated::$webhookSecretSource`, `JobResult::$outcome`.
- The `Scrappy` constructor takes `webhookSecret` and an optional Guzzle client (for tests).

## 0.1.1

- Laravel 9 and Testbench 7 support.

## 0.1.0

- First release.
