<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * Response of POST /v1/jobs. The `webhookSecret` is returned ONCE on
 * creation when the request supplied a `webhook_url` — store it on
 * your side immediately, it's the HMAC key you'll use to verify the
 * inbound webhook signature.
 */
class JobCreated
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?int $estimatedWaitSeconds,
        /** Per-job HMAC secret. Only present when `webhook_url` was set. */
        public readonly ?string $webhookSecret,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['job_id'] ?? ''),
            status: (string) ($data['status'] ?? 'queued'),
            createdAt: (string) ($data['created_at'] ?? ''),
            estimatedWaitSeconds: isset($data['estimated_wait_seconds']) && is_int($data['estimated_wait_seconds'])
                ? $data['estimated_wait_seconds']
                : null,
            webhookSecret: isset($data['webhook_secret']) && is_string($data['webhook_secret'])
                ? $data['webhook_secret']
                : null,
        );
    }
}
