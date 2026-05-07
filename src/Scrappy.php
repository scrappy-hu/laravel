<?php

declare(strict_types=1);

namespace Scrappy;

use Scrappy\Http\Client;
use Scrappy\Resources\Jobs;
use Scrappy\Resources\Me;
use Scrappy\Resources\Webhooks;
use Scrappy\Webhooks\SignatureVerifier;

/**
 * Main entry point. Use the Laravel Facade in app code; instantiate
 * directly only in tests or non-Laravel scripts.
 *
 *     // Laravel
 *     use Scrappy\Facades\Scrappy;
 *     $job = Scrappy::jobs()->create([...]);
 *
 *     // Plain PHP
 *     $client = new Scrappy('sk_live_...');
 *     $job = $client->jobs()->create([...]);
 */
class Scrappy
{
    private readonly Client $http;
    private readonly Jobs $jobs;
    private readonly Me $me;
    private readonly Webhooks $webhooks;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.scrappy.hu',
        int $timeout = 30,
        int $replayWindowSeconds = 300,
    ) {
        $this->http = new Client($apiKey, $baseUrl, $timeout);
        $this->jobs = new Jobs($this->http);
        $this->me = new Me($this->http);
        $this->webhooks = new Webhooks(
            $this->http,
            new SignatureVerifier($replayWindowSeconds),
        );
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function me(): Me
    {
        return $this->me;
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks;
    }
}
