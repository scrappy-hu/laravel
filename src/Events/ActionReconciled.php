<?php

declare(strict_types=1);

namespace Scrappy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Scrappy\Models\ActionResult;
use Scrappy\Models\WebhookEvent;

/**
 * A late worker report settled an action job that had ended Uncertain (action.reconciled): `$action->status` is now Succeeded or Partial.
 *
 * Dispatched by the Route::scrappyWebhook() receiver after the signature
 * is verified. Deliveries can repeat: keep the listener idempotent (key
 * it on `$action->id` or your action_key).
 */
final class ActionReconciled
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $webhook,
        public readonly ActionResult $action,
    ) {
    }
}
