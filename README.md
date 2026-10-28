# Scrappy SDK for Laravel

Submit web-scraping jobs and verify webhooks against
[api.scrappy.hu](https://scrappy.hu) from any Laravel 9 / 10 / 11 / 12 app.

Requires PHP 8.1+ (the SDK uses `readonly` properties for typed
response objects, which Laravel 9 + PHP 8.0 doesn't support — bump
PHP to 8.1+ and you're fine on any Laravel 9.x).

```bash
composer require scrappy-hu/laravel
```

## Installation

```bash
composer require scrappy-hu/laravel
php artisan vendor:publish --tag=scrappy-config
```

Then in `.env`:

```
SCRAPPY_API_KEY=sk_live_...
SCRAPPY_WEBHOOK_SECRET=...
```

Both come from <https://scrappy.hu/dashboard/api-keys>: the API key, and
under **Webhook** the key's signing secret (*Create a key-level secret*,
shown once). Every job of the key is signed with that one secret.

The `ScrappyServiceProvider` is auto-registered via package discovery,
and the `Scrappy` facade is wired up.

## Receive results by webhook

Register the receiver once, e.g. in `routes/api.php`:

```php
Route::scrappyWebhook('scrappy/webhook'); // POST, named `scrappy.webhook`
```

It verifies `X-Scrappy-Signature` with `SCRAPPY_WEBHOOK_SECRET` (401 if it
doesn't match), answers 200 at once, and dispatches an event:

| Event | When |
|---|---|
| `Scrappy\Events\JobCompleted` | the page answered; `$e->webhook->result` holds it (check `->httpStatus`, a 404 completes too) |
| `Scrappy\Events\JobFailed` | no usable answer (timeout, crash, a bot wall on every attempt); `$e->webhook->error` says why |
| `Scrappy\Events\WebhookTestReceived` | a test delivery from `Scrappy::webhooks()->test()` |

Listen with a queued listener, so the receiver answers Scrappy quickly:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Scrappy\Events\JobCompleted;

final class StoreScrapedProduct implements ShouldQueue
{
    public function handle(JobCompleted $event): void
    {
        $webhook = $event->webhook;
        $product = Product::find($webhook->clientReferenceId); // your id, sent back
        $product?->applyScrape($webhook->result->html, $webhook->result->httpStatus);
    }
}
```

Scrappy retries a delivery that doesn't get a 2xx (after 1 min, 5 min,
30 min, 2 h and 6 h), so a delivery can arrive more than once: keep
listeners idempotent.

The route sits outside the CSRF check (Scrappy signs instead). Put it
behind no auth middleware: the signature is the authentication.

## Submit jobs

```php
use Scrappy\Facades\Scrappy;

// Many at once: POST /v1/jobs/batch, 100 per request, in order.
$created = Scrappy::jobs()->createMany(
    $products->map(fn ($p) => [
        'url' => $p->url,
        'client_reference_id' => (string) $p->id, // comes back in the webhook
        'priority' => 'low',                      // bulk refresh: behind on-demand jobs
        'webhook_url' => route('scrappy.webhook'),
        'options' => [
            'wait_until' => 'domcontentloaded',
            'block_trackers' => true,
        ],
    ])->all(),
    idempotencyKey: 'feed-198-'.today()->toDateString(), // safe to retry the whole call
);

// One job:
$job = Scrappy::jobs()->create(['url' => 'https://example.com', 'webhook_url' => route('scrappy.webhook')]);
```

Set the key's default webhook URL in the dashboard and you can leave out
`webhook_url`.

## Read a job

Results also stay readable for 24 hours after a job ends:

```php
$job = Scrappy::jobs()->get($jobId);

if ($job->status === 'completed') {
    $status = $job->result?->httpStatus;
    $html = $job->result?->html;
}
```

## Verify a webhook yourself

`Route::scrappyWebhook()` does this for you. For a hand-written receiver:

```php
if (! Scrappy::webhooks()->verify($request->getContent(), $request->header('X-Scrappy-Signature'))) {
    abort(401);
}
$event = Scrappy::webhooks()->parse($request->getContent()); // Scrappy\Models\WebhookEvent
```

Pass the **raw** body (`$request->getContent()`): re-encoding the JSON
changes the bytes and breaks the HMAC.

## Test your webhook receiver

```php
$result = Scrappy::webhooks()->test(); // the key's webhook URL, or pass one
// $result['delivered'] === true, $result['response_status'] === 200
```

The test delivery is signed with your key's secret, so it exercises the
same verification as real deliveries (and fires `WebhookTestReceived`).

## Upgrading from 0.1

- Webhooks are verified with one key-level secret (`SCRAPPY_WEBHOOK_SECRET`)
  instead of a secret stored per job. Create it in the dashboard first;
  `verify()` throws a `ScrappyException` (`config_error`) without one.
- `Route::scrappyWebhook()` replaces a hand-written receiver.
- `Scrappy::jobs()->createMany()` submits batches.
- `JobCreated::$webhookSecretSource` and `JobResult::$outcome` are new.

## Account snapshot

```php
$snap = Scrappy::me()->get();

echo $snap->planName();             // 'Pro'
echo $snap->monthlyUsed();          // 1234
echo $snap->monthlyRemaining();     // 8766
```

## Errors

Every non-2xx response throws a typed exception you can pattern-match:

```php
use Scrappy\Exceptions\{
    ScrappyException,
    AuthenticationException,
    RateLimitException,
    QuotaExceededException,
    ValidationException,
    NotFoundException,
};

try {
    Scrappy::jobs()->create(['url' => 'https://example.com']);
} catch (RateLimitException $e) {
    sleep($e->retryAfterSeconds());
    // retry…
} catch (QuotaExceededException $e) {
    // surface upgrade CTA to the user
    return redirect($e->upgradeUrl());
} catch (ValidationException $e) {
    foreach ($e->fieldErrors() as $field => $errors) {
        // render errors next to the form field
    }
} catch (AuthenticationException) {
    abort(500, 'Scrappy api key is missing or invalid');
} catch (ScrappyException $e) {
    Log::error('scrappy', [
        'code' => $e->errorCode(),
        'status' => $e->statusCode(),
        'payload' => $e->payload(),
    ]);
    throw $e;
}
```

## Configuration

`config/scrappy.php` (after `vendor:publish`):

| Key                       | Env var                  | Default                     | Notes |
|---------------------------|--------------------------|-----------------------------|-------|
| `api_key`                 | `SCRAPPY_API_KEY`        | —                           | Required. |
| `timeout`                 | `SCRAPPY_TIMEOUT`        | `30`                        | Per-call HTTP timeout (seconds). |
| `webhook_secret`          | `SCRAPPY_WEBHOOK_SECRET` | —                           | The key's webhook signing secret (dashboard → API keys → Webhook). Required for receiving webhooks. |
| `replay_window_seconds`   | —                        | `300`                       | Reject signatures older than this. |

The base URL is intentionally not configurable from Laravel — every
Laravel-driven instance points at `https://api.scrappy.hu`. Plain-PHP
users can still override it via the `Scrappy` constructor for tests
or unusual self-hosted setups.

## Plain PHP usage

The package works outside Laravel too — instantiate the client directly:

```php
$scrappy = new \Scrappy\Scrappy(
    apiKey: getenv('SCRAPPY_API_KEY'),
);
$job = $scrappy->jobs()->create(['url' => 'https://example.com']);
```

## Testing your own code

The SDK throws on api errors instead of returning bad data, which makes
mocking straightforward:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$handler = new MockHandler([
    new Response(201, [], json_encode([
        'job_id' => 'test-id',
        'status' => 'queued',
        'created_at' => '2026-05-08T10:00:00Z',
    ])),
]);
$scrappy = new \Scrappy\Scrappy('sk_live_test', guzzle: new Client([
    'handler' => HandlerStack::create($handler),
    'base_uri' => 'https://api.scrappy.hu/',
]));
```

## API reference

Full reference + interactive API explorer:

- <https://scrappy.hu/docs> — long-form reference
- <https://scrappy.hu/docs/api> — interactive (Scalar)
- <https://scrappy.hu/openapi.json> — OpenAPI 3.1 spec (machine-readable)

## Versioning

This SDK follows semver. Breaking changes go in major versions; new
methods + bug fixes go in minors / patches. Tracked at
<https://github.com/scrappy-hu/laravel/releases>.

## License

[MIT](LICENSE).
