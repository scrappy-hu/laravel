<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * One webhook delivery from Scrappy.
 *
 *  - `job.completed`: the page gave an answer; `result` holds it (check
 *    `result->httpStatus`, it may be a 404).
 *  - `job.failed`: no usable answer; `result` is null, `error` says why.
 *  - `webhook.test`: a test delivery from POST /v1/webhooks/test.
 *
 * `clientReferenceId` and `metadata` come back as you sent them, so a
 * receiver can find its own record without storing Scrappy job ids.
 */
class WebhookEvent
{
    public const COMPLETED = 'job.completed';
    public const FAILED = 'job.failed';
    public const TEST = 'webhook.test';

    public function __construct(
        public readonly string $event,
        public readonly ?string $jobId,
        public readonly ?string $status,
        public readonly ?string $url,
        public readonly ?string $clientReferenceId,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata,
        public readonly ?JobResult $result,
        public readonly ?string $error,
        public readonly ?int $attempts,
        public readonly ?string $completedAt,
        public readonly ?string $timestamp,
        /** @var array<string, mixed> */
        private readonly array $raw,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->event === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->event === self::FAILED;
    }

    public function isTest(): bool
    {
        return $this->event === self::TEST;
    }

    /**
     * The decoded body, for fields not mapped here.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $str = static fn (string $k): ?string => isset($data[$k]) && is_string($data[$k]) ? $data[$k] : null;

        return new self(
            event: (string) ($data['event'] ?? ''),
            jobId: $str('job_id'),
            status: $str('status'),
            url: $str('url'),
            clientReferenceId: $str('client_reference_id'),
            metadata: isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            result: isset($data['result']) && is_array($data['result']) ? JobResult::fromArray($data['result']) : null,
            error: $str('error'),
            attempts: isset($data['attempts']) && is_int($data['attempts']) ? $data['attempts'] : null,
            completedAt: $str('completed_at'),
            timestamp: $str('timestamp'),
            raw: $data,
        );
    }
}
