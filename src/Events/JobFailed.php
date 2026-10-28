<?php

declare(strict_types=1);

namespace Scrappy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Scrappy\Models\WebhookEvent;

/**
 * A job ended without a usable answer (job.failed): timeout, crash or a bot wall on every attempt. `$event->error` says why.
 *
 * Dispatched by the Route::scrappyWebhook() receiver after the signature
 * is verified. Make the listener ShouldQueue for anything slow: the
 * receiver answers Scrappy only after synchronous listeners return.
 */
final class JobFailed
{
    use Dispatchable;

    public function __construct(public readonly WebhookEvent $webhook)
    {
    }
}
