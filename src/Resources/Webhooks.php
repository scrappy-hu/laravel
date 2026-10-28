<?php

declare(strict_types=1);

namespace Scrappy\Resources;

use Scrappy\Exceptions\ScrappyException;
use Scrappy\Http\Client;
use Scrappy\Models\WebhookEvent;
use Scrappy\Webhooks\SignatureVerifier;

/**
 * Webhooks: test your receiver, verify inbound signatures, parse them.
 *
 * Most apps don't call these directly: Route::scrappyWebhook() does the
 * verifying and dispatches events. Use them for a hand-written receiver:
 *
 *     if (!Scrappy::webhooks()->verify($request->getContent(), $request->header('X-Scrappy-Signature'))) {
 *         abort(401);
 *     }
 *     $event = Scrappy::webhooks()->parse($request->getContent());
 */
class Webhooks
{
    public function __construct(
        private readonly Client $http,
        private readonly SignatureVerifier $verifier,
        private readonly ?string $secret = null,
    ) {
    }

    /**
     * POSTs a `webhook.test` delivery to the URL (default: the key's
     * webhook URL), signed with the key's webhook secret. Returns the
     * delivery report (status, response, duration).
     *
     * @return array<string, mixed>
     */
    public function test(?string $webhookUrl = null): array
    {
        return $this->http->post('/v1/webhooks/test', $webhookUrl === null ? [] : ['webhook_url' => $webhookUrl]);
    }

    /**
     * Verify the X-Scrappy-Signature header with the key's webhook secret
     * (SCRAPPY_WEBHOOK_SECRET), or the one given. False on any mismatch:
     * missing or malformed header, a stale timestamp, a tampered body.
     * Pass the RAW body: re-encoding JSON changes the bytes.
     *
     * @throws ScrappyException when no secret is configured
     */
    public function verify(string $rawBody, ?string $header, ?string $secret = null, ?int $now = null): bool
    {
        $secret ??= $this->secret;
        if ($secret === null || $secret === '') {
            throw new ScrappyException(
                'Scrappy webhook secret is not set: create one in the dashboard (API keys → Webhook) and set SCRAPPY_WEBHOOK_SECRET.',
                'config_error',
            );
        }

        return $this->verifier->verify($rawBody, $header, $secret, $now);
    }

    /**
     * Decode a webhook body. Verify first.
     *
     * @throws ScrappyException on a body that isn't a JSON object
     */
    public function parse(string $rawBody): WebhookEvent
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new ScrappyException('Scrappy webhook body is not a JSON object.', 'invalid_webhook');
        }

        return WebhookEvent::fromArray($data);
    }
}
