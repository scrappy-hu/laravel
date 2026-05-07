<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * One-call account snapshot from GET /v1/me. Use this to render a
 * "you're on plan X, used Y / Z this month" widget without three
 * separate api calls.
 */
class MeSnapshot
{
    public function __construct(
        /** @var array<string, mixed> */
        public readonly array $apiKey,
        /** @var array<string, mixed>|null */
        public readonly ?array $plan,
        /** @var array<string, mixed> */
        public readonly array $usage,
        /** @var array<string, mixed> */
        private readonly array $raw,
    ) {
    }

    /**
     * Full decoded response, in case the api adds fields we don't
     * expose as named accessors.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function planName(): ?string
    {
        $name = $this->plan['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function monthlyUsed(): ?int
    {
        $used = $this->usage['monthly_used'] ?? null;

        return is_int($used) ? $used : null;
    }

    public function monthlyQuota(): ?int
    {
        $quota = $this->usage['monthly_quota'] ?? null;

        return is_int($quota) ? $quota : null;
    }

    public function monthlyRemaining(): ?int
    {
        $remaining = $this->usage['monthly_remaining'] ?? null;

        return is_int($remaining) ? $remaining : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: is_array($data['api_key'] ?? null) ? $data['api_key'] : [],
            plan: is_array($data['plan'] ?? null) ? $data['plan'] : null,
            usage: is_array($data['usage'] ?? null) ? $data['usage'] : [],
            raw: $data,
        );
    }
}
