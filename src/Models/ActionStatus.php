<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * Where an action job stands.
 *
 *  - Succeeded: every step ran, or the commit (e.g. the order) was
 *    confirmed. Check `outputIncomplete` if you need every output field.
 *  - Rejected: nothing changed on the site. Safe to submit again.
 *  - Partial: something changed and stayed changed (an item left in a
 *    cart). Needs cleaning up by hand.
 *  - Uncertain: a step that changes something started and its result is
 *    unknown. Never resubmit automatically: check on the site.
 *  - Timeout: the SDK stopped waiting before the job ended. If
 *    `mutationStarted` is true, treat it as Uncertain; the final outcome
 *    arrives by webhook (ActionCompleted) or Scrappy::actions()->get().
 *  - Queued / Running: not ended yet (submit() and get() only).
 */
enum ActionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Rejected = 'rejected';
    case Partial = 'partial';
    case Uncertain = 'uncertain';
    case Timeout = 'timeout';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Rejected, self::Partial, self::Uncertain => true,
            default => false,
        };
    }
}
