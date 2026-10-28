<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * One created job (POST /v1/jobs, an entry of POST /v1/jobs/batch).
 *
 * `webhookSecretSource` says which secret signs its webhooks: `api_key`
 * (your SCRAPPY_WEBHOOK_SECRET; `webhookSecret` is then null) or `job`
 * (a key without its own secret: `webhookSecret` is this job's, shown
 * only here).
 */
class JobCreated
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?int $estimatedWaitSeconds,
        /** This job's own secret, only for a key without a key-level one. */
        public readonly ?string $webhookSecret,
        public readonly ?string $webhookSecretSource,
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
            webhookSecretSource: isset($data['webhook_secret_source']) && is_string($data['webhook_secret_source'])
                ? $data['webhook_secret_source']
                : null,
        );
    }
}
