<?php

declare(strict_types=1);

namespace Scrappy\Exceptions;

/**
 * 429 — too many requests for this api key. Use `retryAfterSeconds()`
 * to back off the right amount before retrying.
 */
class RateLimitException extends ScrappyException
{
    /**
     * Seconds remaining on the rate-limit window. Pulled from the
     * `retry_after_seconds` field in the response body — falls back
     * to 60 if the body shape is unexpected.
     */
    public function retryAfterSeconds(): int
    {
        $value = $this->payload['retry_after_seconds'] ?? null;

        return is_int($value) ? $value : 60;
    }
}
