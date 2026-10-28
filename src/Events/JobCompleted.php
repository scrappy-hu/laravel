<?php

declare(strict_types=1);

namespace Scrappy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Scrappy\Models\WebhookEvent;

/**
 * A job got an answer from the page (job.completed). Check `$event->result->httpStatus`: a 404 is a completed job too.
 *
 * Dispatched by the Route::scrappyWebhook() receiver after the signature
 * is verified. Make the listener ShouldQueue for anything slow: the
 * receiver answers Scrappy only after synchronous listeners return.
 */
final class JobCompleted
{
    use Dispatchable;

    public function __construct(public readonly WebhookEvent $webhook)
    {
    }
}
