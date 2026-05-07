<?php

declare(strict_types=1);

namespace Scrappy\Resources;

use Scrappy\Http\Client;
use Scrappy\Models\Job;
use Scrappy\Models\JobCreated;

/**
 * Job operations — the bulk of the api surface.
 *
 *     $job = Scrappy::jobs()->create([
 *         'url' => 'https://example.com',
 *         'webhook_url' => route('scrappy.webhook'),
 *         'metadata' => ['order_id' => $order->id],
 *     ]);
 *     $status = Scrappy::jobs()->get($job->id);
 */
class Jobs
{
    public function __construct(private readonly Client $http)
    {
    }

    /**
     * Submit a new scrape job. Returns immediately with a queued
     * job_id — workers pick it up via long-poll within seconds.
     *
     * Pass `idempotency_key` to make the call safe to retry across
     * network failures: a second call within 24h with the same key
     * + same body returns the original response without creating a
     * duplicate job.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $idempotencyKey  optional Idempotency-Key header
     */
    public function create(array $payload, ?string $idempotencyKey = null): JobCreated
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];
        $body = $this->http->post('/v1/jobs', $payload, $headers);

        return JobCreated::fromArray($body);
    }

    /** Fetch a job by id. Returns the full Job — including `result` once terminal. */
    public function get(string $jobId): Job
    {
        $body = $this->http->get("/v1/jobs/{$jobId}");

        return Job::fromArray($body);
    }

    /**
     * List jobs submitted by the calling api key. Reverse-chronological,
     * cursor-paginated. Returns the raw associative array straight
     * from the api so consumers can iterate `next_cursor` themselves.
     *
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, next_cursor?: string|null}
     */
    public function list(array $query = []): array
    {
        /** @var array{data: list<array<string, mixed>>, next_cursor?: string|null} $body */
        $body = $this->http->get('/v1/jobs', $query);

        return $body;
    }

    /** Cancel a queued or assigned job. 409 if it's already terminal. */
    public function cancel(string $jobId): Job
    {
        $body = $this->http->delete("/v1/jobs/{$jobId}");

        return Job::fromArray($body);
    }

    /**
     * Retry a failed or cancelled job. Clones the original config
     * (url, options, metadata) into a fresh queued job — returns the
     * NEW job_id. The original stays in its terminal state.
     */
    public function retry(string $jobId): JobCreated
    {
        $body = $this->http->post("/v1/jobs/{$jobId}/retry", []);

        return JobCreated::fromArray($body);
    }
}
