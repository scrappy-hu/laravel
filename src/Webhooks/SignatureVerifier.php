<?php

declare(strict_types=1);

namespace Scrappy\Webhooks;

/**
 * Verifies the X-Scrappy-Signature header against a per-job webhook
 * secret. The header format is Stripe-style:
 *
 *     X-Scrappy-Signature: t=1746381057,v1=<hex sha256 of t.body>
 *
 * The signed value is `{timestamp}.{raw body}` — the `t=` from the
 * header, a literal dot, then the raw request body bytes verbatim
 * (do NOT json_decode + re-encode before verifying — that changes
 * the bytes and breaks the HMAC).
 *
 * Defends against replay by refusing signatures whose timestamp is
 * older than `replayWindowSeconds` (default 5 minutes — matches the
 * Scrappy server's emit window).
 *
 * Returns false instead of throwing — pattern a controller wants is
 * `if (!verify(...)) abort(401)`, not try/catch.
 */
final class SignatureVerifier
{
    public function __construct(
        private readonly int $replayWindowSeconds = 300,
    ) {
    }

    /**
     * @param  string  $rawBody       the request body bytes verbatim (Request::getContent() in Laravel)
     * @param  string|null  $header   the X-Scrappy-Signature header
     * @param  string  $secret        per-job HMAC secret returned by POST /v1/jobs
     * @param  int|null  $now         override "now" for testing — unix seconds; defaults to time()
     */
    public function verify(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        [$t, $v1] = $this->parseHeader($header);
        if ($t === null || $v1 === null) {
            return false;
        }

        // Timestamp window: refuse signatures older than the replay
        // limit. Allow ~60s into the future to tolerate clock skew.
        $current = $now ?? time();
        $age = $current - $t;
        if ($age > $this->replayWindowSeconds || $age < -60) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);

        // hash_equals does a length check internally and a constant-time
        // compare. Using === would be a timing-attack vector.
        return hash_equals($expected, $v1);
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function parseHeader(string $header): array
    {
        $t = null;
        $v1 = null;
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key = substr($part, 0, $eq);
            $value = substr($part, $eq + 1);
            if ($key === 't' && ctype_digit($value)) {
                $t = (int) $value;
            } elseif ($key === 'v1') {
                $v1 = $value;
            }
        }

        return [$t, $v1];
    }
}
