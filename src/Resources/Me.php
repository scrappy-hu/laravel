<?php

declare(strict_types=1);

namespace Scrappy\Resources;

use Scrappy\Http\Client;
use Scrappy\Models\MeSnapshot;

/**
 * Account info for the calling api key. Single call, intended for
 * dashboards: "you're on plan X, used Y of Z this month".
 *
 *     $snap = Scrappy::me()->get();
 *     $remaining = $snap->monthlyRemaining();
 */
class Me
{
    public function __construct(private readonly Client $http)
    {
    }

    public function get(): MeSnapshot
    {
        $body = $this->http->get('/v1/me');

        return MeSnapshot::fromArray($body);
    }
}
