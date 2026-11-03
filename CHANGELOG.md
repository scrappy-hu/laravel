# Changelog

## 0.3.0

- `Scrappy::actions()`: action jobs (preview). `run()` submits and waits for the outcome (`ActionResult` with an `ActionStatus` enum: Succeeded, Rejected, Partial, Uncertain, Timeout); `submit()`, `get()`, `wait()`, `cancel()`, `logout()`.
- Secrets are sealed to Scrappy's public key in the SDK (`ext-sodium`, libsodium sealed box) before they are sent; the key is fetched once and again after a rotation.
- `session` (continue a named browser session), `actionKey` (never runs twice; a repeat call follows the earlier job), `dryRun`.
- Webhooks: `action.completed` → `Scrappy\Events\ActionCompleted`, `action.reconciled` → `ActionReconciled`; `WebhookEvent::action()`.
- `Client::get()` / `post()` take a per-request timeout.

## 0.2.1

- `SCRAPPY_WEBHOOK_SECRET` is read again: in `config/scrappy.php` the `webhook_secret` key sat inside an unclosed comment block, so `verify()` and `Route::scrappyWebhook()` threw `config_error` unless the secret was set in code. If you published the config, republish it (`php artisan vendor:publish --tag=scrappy-config --force`) or close the comment above `webhook_secret` yourself.

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
