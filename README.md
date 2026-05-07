# Scrappy SDK for Laravel

Submit web-scraping jobs and verify webhooks against
[api.scrappy.hu](https://scrappy.hu) from any Laravel 10 / 11 / 12 app.

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
```

Generate the key from <https://scrappy.hu/dashboard/api-keys>.

That's it — the package's `ScrappyServiceProvider` is auto-registered
via Laravel's package discovery, and the `Scrappy` facade is wired up.

## Submit a job

```php
use Scrappy\Facades\Scrappy;

$job = Scrappy::jobs()->create([
    'url' => 'https://example.com/products/widget',
    'options' => [
        'extract' => ['title', 'description', 'tables'],
    ],
    'webhook_url' => route('scrappy.webhook'),
    'metadata' => ['order_id' => $order->id],
]);

// store $job->webhookSecret somewhere keyed by $job->id —
// you'll need it to verify the inbound webhook.
DB::table('scrappy_jobs')->insert([
    'id' => $job->id,
    'order_id' => $order->id,
    'webhook_secret' => $job->webhookSecret,
    'created_at' => now(),
]);

return ['job_id' => $job->id, 'status' => $job->status];
```

## Read a job

```php
$job = Scrappy::jobs()->get($jobId);

if ($job->status === 'completed') {
    $title = $job->result?->title;
    $html = $job->result?->html;
}
```

## Verify a webhook

```php
use Illuminate\Http\Request;
use Scrappy\Facades\Scrappy;

Route::post('/scrappy/webhook', function (Request $request) {
    $jobId = $request->header('X-Scrappy-Job-Id');
    $stored = DB::table('scrappy_jobs')->where('id', $jobId)->first();
    if (! $stored) {
        abort(404);
    }

    $verified = Scrappy::webhooks()->verify(
        rawBody: $request->getContent(),
        header: $request->header('X-Scrappy-Signature'),
        secret: $stored->webhook_secret,
    );
    if (! $verified) {
        abort(401, 'Invalid signature');
    }

    $event = $request->json()->all();
    if ($event['event'] === 'job.completed') {
        // … process the result
    }

    return response()->noContent();
});
```

**Critical**: pass the **raw** request body (`$request->getContent()`).
Re-serialising via `json_encode($request->json())` reorders / spaces
keys differently and breaks the HMAC.

## Test your webhook receiver

Before going live, fire a test event from the SDK to verify your
endpoint is reachable + signature verification works end-to-end:

```php
$result = Scrappy::webhooks()->test('https://your-app.example.com/scrappy/webhook');
// $result['delivered'] === true
// $result['response_status'] === 200
```

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
| `base_url`                | `SCRAPPY_BASE_URL`       | `https://api.scrappy.hu`    | Override for self-hosted. |
| `timeout`                 | `SCRAPPY_TIMEOUT`        | `30`                        | Per-call HTTP timeout (seconds). |
| `webhook_secret`          | `SCRAPPY_WEBHOOK_SECRET` | —                           | Optional default secret for `verify()`. |
| `replay_window_seconds`   | —                        | `300`                       | Reject signatures older than this. |

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
$scrappy = new \Scrappy\Scrappy('sk_live_test', 'https://api.scrappy.hu');
// (For full mocking inject a Guzzle client into Scrappy\Http\Client — see tests/.)
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
