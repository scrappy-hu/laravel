<?php

declare(strict_types=1);

namespace Scrappy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Scrappy\Events\JobCompleted;
use Scrappy\Events\JobFailed;
use Scrappy\Events\WebhookTestReceived;
use Scrappy\Exceptions\ScrappyException;
use Scrappy\Scrappy;

/**
 * The receiver behind Route::scrappyWebhook(): verify, dispatch, answer.
 * 401 on a bad signature, 400 on a body that isn't a webhook, else 200.
 * Scrappy retries non-2xx answers (1 min … 6 h), so a listener that
 * throws gets the delivery again: keep handlers idempotent.
 */
final class WebhookController
{
    public function __invoke(Request $request, Scrappy $scrappy): JsonResponse
    {
        $body = $request->getContent();
        if (!$scrappy->webhooks()->verify($body, $request->header('X-Scrappy-Signature'))) {
            return new JsonResponse(['error' => 'invalid_signature'], 401);
        }

        try {
            $event = $scrappy->webhooks()->parse($body);
        } catch (ScrappyException) {
            return new JsonResponse(['error' => 'invalid_body'], 400);
        }

        match (true) {
            $event->isCompleted() => JobCompleted::dispatch($event),
            $event->isFailed() => JobFailed::dispatch($event),
            $event->isTest() => WebhookTestReceived::dispatch($event),
            default => null,
        };

        return new JsonResponse(['received' => true]);
    }
}
