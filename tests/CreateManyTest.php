<?php

declare(strict_types=1);

namespace Scrappy\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Scrappy\Scrappy;

/**
 * createMany(): POST /v1/jobs/batch in chunks of 100, in order, one
 * JobCreated per input, chunk-suffixed idempotency keys.
 */
final class CreateManyTest extends TestCase
{
    public function test_chunks_of_100_in_order_with_idempotency_per_chunk(): void
    {
        $history = [];
        $mock = new MockHandler();
        foreach ([100, 100, 50] as $chunk => $size) {
            $mock->append(new Response(202, [], (string) json_encode([
                'jobs' => array_map(fn (int $i) => [
                    'job_id' => sprintf('job-%d', $chunk * 100 + $i),
                    'status' => 'queued',
                    'created_at' => '2026-10-28T08:00:00Z',
                    'webhook_secret_source' => 'api_key',
                ], range(0, $size - 1)),
                'count' => $size,
            ])));
        }
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $scrappy = new Scrappy('sk_live_test', 'https://api.example.test', guzzle: new Guzzle(['handler' => $stack, 'base_uri' => 'https://api.example.test/']));

        $payloads = array_map(fn (int $i) => ['url' => "https://example.com/{$i}", 'client_reference_id' => "p{$i}"], range(0, 249));
        $created = $scrappy->jobs()->createMany($payloads, 'feed-198-2026-10-28');

        $this->assertCount(250, $created);
        $this->assertSame('job-0', $created[0]->id);
        $this->assertSame('job-249', $created[249]->id);
        $this->assertSame('api_key', $created[0]->webhookSecretSource);
        $this->assertNull($created[0]->webhookSecret);

        $this->assertCount(3, $history);
        foreach ($history as $n => $entry) {
            $this->assertSame('/v1/jobs/batch', $entry['request']->getUri()->getPath());
            $this->assertSame("feed-198-2026-10-28:{$n}", $entry['request']->getHeaderLine('Idempotency-Key'));
        }
        $first = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertCount(100, $first['jobs']);
        $this->assertSame('p0', $first['jobs'][0]['client_reference_id']);
        $last = json_decode((string) $history[2]['request']->getBody(), true);
        $this->assertCount(50, $last['jobs']);
    }

    public function test_no_idempotency_header_without_a_key(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(202, [], '{"jobs":[{"job_id":"a","status":"queued","created_at":"x"}],"count":1}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $scrappy = new Scrappy('sk_live_test', guzzle: new Guzzle(['handler' => $stack, 'base_uri' => 'https://api.example.test/']));

        $scrappy->jobs()->createMany([['url' => 'https://example.com']]);
        $this->assertFalse($history[0]['request']->hasHeader('Idempotency-Key'));
    }
}
