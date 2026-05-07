<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * A scrape job at any point in its lifecycle.
 *
 * Use `->result` only when status is one of the terminal values
 * (`completed`, `failed`, `cancelled`); it's null while the job is
 * still queued / running. The `raw()` accessor exposes the full
 * decoded api response for fields the typed properties don't cover
 * (e.g. extension metadata added in newer api versions).
 */
class Job
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $status,
        public readonly string $priority,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $clientReferenceId,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata,
        public readonly ?string $error,
        public readonly string $createdAt,
        public readonly string $queuedAt,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly ?JobResult $result,
        /** @var array<string, mixed> */
        private readonly array $raw,
    ) {
    }

    /**
     * Full decoded response body, in case a future api field hasn't
     * been mapped onto a typed property here yet.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawResult = $data['result'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? $data['job_id'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            status: (string) ($data['status'] ?? 'queued'),
            priority: (string) ($data['priority'] ?? 'normal'),
            attempts: (int) ($data['attempts'] ?? 0),
            maxAttempts: (int) ($data['max_attempts'] ?? 3),
            clientReferenceId: isset($data['client_reference_id']) && is_string($data['client_reference_id'])
                ? $data['client_reference_id']
                : null,
            metadata: isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            error: isset($data['error']) && is_string($data['error']) ? $data['error'] : null,
            createdAt: (string) ($data['created_at'] ?? ''),
            queuedAt: (string) ($data['queued_at'] ?? $data['created_at'] ?? ''),
            startedAt: isset($data['started_at']) && is_string($data['started_at']) ? $data['started_at'] : null,
            completedAt: isset($data['completed_at']) && is_string($data['completed_at']) ? $data['completed_at'] : null,
            result: is_array($rawResult) ? JobResult::fromArray($rawResult) : null,
            raw: $data,
        );
    }
}
