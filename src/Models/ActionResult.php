<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * An action job as Scrappy reports it (POST/GET /v1/actions, and the
 * action.completed / action.reconciled webhooks).
 *
 *     match ($result->status) {
 *         ActionStatus::Succeeded => $po->markOrdered($result->output['order_id']),
 *         ActionStatus::Rejected  => $po->markRejected($result->message),   // safe to retry
 *         ActionStatus::Partial   => $po->needsManualCleanup($result),
 *         ActionStatus::Uncertain,
 *         ActionStatus::Timeout   => $po->needsVerification($result),       // never resubmit automatically
 *         default                 => null,                                  // still queued / running
 *     };
 */
class ActionResult
{
    public function __construct(
        public readonly string $id,
        public readonly ActionStatus $status,
        /** Why it ended this way: dry_run, price_too_high, not_applied, worker_lost, not_started … */
        public readonly ?string $reason,
        public readonly ?string $failedStep,
        public readonly ?string $message,
        /** @var array<string, mixed> */
        public readonly array $output,
        /** Succeeded after the commit, but a later step failed: some output may be missing. */
        public readonly bool $outputIncomplete,
        /** Rejected because the undo steps restored the site. */
        public readonly bool $compensated,
        /** A step that changes something was started. */
        public readonly bool $mutationStarted,
        public readonly bool $dryRun,
        /** @var array<string, mixed>|null  dry_run: { step, found, actionable, would_send?, message? } */
        public readonly ?array $dryRunResult,
        /** @var list<array<string, mixed>> */
        public readonly array $steps,
        public readonly ?string $actionKey,
        public readonly ?string $clientReferenceId,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata,
        public readonly ?string $createdAt,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly ?string $deadlineAt,
        /** Set when a late worker report settled an uncertain job. */
        public readonly ?string $reconciledAt,
        /** @var array<string, mixed> */
        private readonly array $raw,
    ) {
    }

    /** Nothing changed on the site: submitting again is safe. */
    public function safeToRetry(): bool
    {
        return $this->status === ActionStatus::Rejected;
    }

    /** Someone has to look at the site before anything else happens. */
    public function needsVerification(): bool
    {
        return $this->status === ActionStatus::Uncertain
            || ($this->status === ActionStatus::Timeout && $this->mutationStarted);
    }

    /** The same result, but the SDK stopped waiting for it. */
    public function asTimeout(): self
    {
        return new self(
            $this->id, ActionStatus::Timeout, $this->reason, $this->failedStep, $this->message, $this->output,
            $this->outputIncomplete, $this->compensated, $this->mutationStarted, $this->dryRun, $this->dryRunResult,
            $this->steps, $this->actionKey, $this->clientReferenceId, $this->metadata, $this->createdAt,
            $this->startedAt, $this->completedAt, $this->deadlineAt, $this->reconciledAt, $this->raw,
        );
    }

    /**
     * The decoded response, for fields not mapped here.
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
        $arr = static fn (string $k): ?array => isset($data[$k]) && is_array($data[$k]) ? $data[$k] : null;

        return new self(
            id: (string) ($data['action_id'] ?? ''),
            status: ActionStatus::tryFrom((string) ($data['status'] ?? '')) ?? ActionStatus::Running,
            reason: $str('reason'),
            failedStep: $str('failed_step'),
            message: $str('message'),
            output: $arr('output') ?? [],
            outputIncomplete: ($data['output_incomplete'] ?? false) === true,
            compensated: ($data['compensated'] ?? false) === true,
            mutationStarted: ($data['mutation_started'] ?? false) === true,
            dryRun: ($data['dry_run'] ?? false) === true,
            dryRunResult: $arr('dry_run_result'),
            steps: array_values(array_filter($arr('steps') ?? [], 'is_array')),
            actionKey: $str('action_key'),
            clientReferenceId: $str('client_reference_id'),
            metadata: $arr('metadata'),
            createdAt: $str('created_at'),
            startedAt: $str('started_at'),
            completedAt: $str('completed_at'),
            deadlineAt: $str('deadline_at'),
            reconciledAt: $str('reconciled_at'),
            raw: $data,
        );
    }
}
