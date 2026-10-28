<?php

declare(strict_types=1);

namespace Scrappy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Scrappy\Models\WebhookEvent;

/**
 * A test delivery from POST /v1/webhooks/test arrived and its signature checked out.
 *
 * Dispatched by the Route::scrappyWebhook() receiver after the signature
 * is verified. Make the listener ShouldQueue for anything slow: the
 * receiver answers Scrappy only after synchronous listeners return.
 */
final class WebhookTestReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookEvent $webhook)
    {
    }
}
