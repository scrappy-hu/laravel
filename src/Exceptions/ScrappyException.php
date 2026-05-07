<?php

declare(strict_types=1);

namespace Scrappy\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for every exception thrown by the SDK. Carries the api's
 * `error` code (stable, pattern-matchable) plus the http status,
 * so consumer code can branch on either:
 *
 *     try {
 *         Scrappy::jobs()->create([...]);
 *     } catch (\Scrappy\Exceptions\RateLimitException $e) {
 *         $retry = $e->retryAfterSeconds();
 *     } catch (\Scrappy\Exceptions\ScrappyException $e) {
 *         logger()->error('scrappy', ['error' => $e->errorCode(), 'status' => $e->statusCode()]);
 *     }
 *
 * Pattern-match on the typed subclass (RateLimit, Validation, ...) when
 * a specific behaviour applies; fall back to ScrappyException for the
 * generic "something went wrong server-side" path.
 */
class ScrappyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload  the full decoded api response body, for diagnostics
     */
    public function __construct(
        string $message,
        protected string $errorCode = 'unknown',
        protected int $statusCode = 0,
        protected array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Stable error code from the api response body — see
     * https://scrappy.hu/docs/errors for the catalog. Examples:
     * `rate_limit_exceeded`, `quota_exceeded`, `validation_error`.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** Http status code from the response (0 if the request never landed). */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Full decoded api response body. Useful when an error type carries
     * extra fields (e.g. `monthly_quota` on `quota_exceeded`) that the
     * typed accessors don't expose.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Factory that picks the right subclass based on the api's error
     * code. Always returns SOME ScrappyException — the generic class
     * is the fallback for unknown codes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(int $statusCode, array $payload): self
    {
        $errorCode = is_string($payload['error'] ?? null) ? $payload['error'] : 'unknown';
        $message = is_string($payload['message'] ?? null)
            ? $payload['message']
            : "Scrappy API returned {$statusCode} ({$errorCode})";

        return match ($errorCode) {
            'unauthorized' => new AuthenticationException($message, $errorCode, $statusCode, $payload),
            'rate_limit_exceeded' => new RateLimitException($message, $errorCode, $statusCode, $payload),
            'quota_exceeded' => new QuotaExceededException($message, $errorCode, $statusCode, $payload),
            'validation_error', 'invalid_request' => new ValidationException($message, $errorCode, $statusCode, $payload),
            'not_found' => new NotFoundException($message, $errorCode, $statusCode, $payload),
            default => new self($message, $errorCode, $statusCode, $payload),
        };
    }
}
