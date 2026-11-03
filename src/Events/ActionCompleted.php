<?php

declare(strict_types=1);

namespace Scrappy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Scrappy\Models\ActionResult;
use Scrappy\Models\WebhookEvent;

/**
 * An action job ended, any outcome (action.completed). Branch on `$action->status`; a Rejected one is safe to submit again, an Uncertain one never automatically.
 *
 * Dispatched by the Route::scrappyWebhook() receiver after the signature
 * is verified. Deliveries can repeat: keep the listener idempotent (key
 * it on `$action->id` or your action_key).
 */
final class ActionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $webhook,
        public readonly ActionResult $action,
    ) {
    }
}
