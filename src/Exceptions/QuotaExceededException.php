<?php

declare(strict_types=1);

namespace Scrappy\Exceptions;

/**
 * 403 quota_exceeded — monthly job cap reached on this plan. Carries
 * the plan name + upgrade URL so consumer code can render a CTA.
 */
class QuotaExceededException extends ScrappyException
{
    public function planName(): ?string
    {
        $plan = $this->payload['plan'] ?? null;

        return is_string($plan) ? $plan : null;
    }

    public function monthlyUsed(): ?int
    {
        $used = $this->payload['monthly_used'] ?? null;

        return is_int($used) ? $used : null;
    }

    public function monthlyQuota(): ?int
    {
        $quota = $this->payload['monthly_quota'] ?? null;

        return is_int($quota) ? $quota : null;
    }

    public function upgradeUrl(): ?string
    {
        $url = $this->payload['upgrade_url'] ?? null;

        return is_string($url) ? $url : null;
    }
}
