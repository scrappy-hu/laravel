<?php

declare(strict_types=1);

namespace Scrappy\Resources;

use Scrappy\Http\Client;
use Scrappy\Webhooks\SignatureVerifier;

/**
 * Webhook helpers — testing your receiver via POST /v1/webhooks/test
 * and verifying inbound signatures via the local HMAC code path.
 *
 *     // Confirm reachability + signature handling end-to-end:
 *     $result = Scrappy::webhooks()->test('https://your-app.example.com/hook');
 *     // $result['delivered'] === true on success
 *
 *     // In your webhook controller:
 *     if (!Scrappy::webhooks()->verify($request->getContent(), $request->header('X-Scrappy-Signature'), $secret)) {
 *         abort(401);
 *     }
 */
class Webhooks
{
    public function __construct(
        private readonly Client $http,
        private readonly SignatureVerifier $verifier,
    ) {
    }

    /**
     * Synchronously POSTs a `webhook.test` envelope to your URL and
     * returns the receiver's response. Used to confirm the endpoint
     * is reachable + the signature verifier accepts a real Scrappy
     * payload — without burning a real scrape job.
     *
     * @return array<string, mixed>
     */
    public function test(string $webhookUrl): array
    {
        return $this->http->post('/v1/webhooks/test', ['webhook_url' => $webhookUrl]);
    }

    /**
     * Verify the X-Scrappy-Signature header against a per-job secret.
     * Returns true on match, false on any failure (missing header,
     * malformed signature, replay, or wrong secret). Pass the RAW
     * request body — re-serialising via json_decode/encode breaks
     * the HMAC.
     */
    public function verify(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        return $this->verifier->verify($rawBody, $header, $secret, $now);
    }
}
