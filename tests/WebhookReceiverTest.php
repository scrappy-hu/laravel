<?php

declare(strict_types=1);

namespace Scrappy\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Scrappy\Events\JobCompleted;
use Scrappy\Events\JobFailed;
use Scrappy\Events\WebhookTestReceived;
use Scrappy\Exceptions\ScrappyException;
use Scrappy\Facades\Scrappy;
use Scrappy\ScrappyServiceProvider;

/**
 * Route::scrappyWebhook(): signed deliveries become events, anything
 * unsigned or tampered gets 401, a non-JSON body 400. verify() and
 * parse() use SCRAPPY_WEBHOOK_SECRET.
 */
final class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

    private const EVENTS = [JobCompleted::class, JobFailed::class, WebhookTestReceived::class];

    protected function getPackageProviders($app): array
    {
        return [ScrappyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scrappy.api_key', 'sk_live_test');
        $app['config']->set('scrappy.webhook_secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        Route::scrappyWebhook();
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, ?string $secret = self::SECRET, ?string $body = null)
    {
        $body ??= (string) json_encode($payload);
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret ?? '');

        return $this->call('POST', '/scrappy/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SCRAPPY_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_completed_delivery_dispatches_job_completed_with_the_result(): void
    {
        Event::fake(self::EVENTS);
        $this->deliver([
            'event' => 'job.completed',
            'job_id' => 'j1',
            'status' => 'completed',
            'url' => 'https://www.alza.hu/p',
            'client_reference_id' => 'product-42',
            'metadata' => ['feed_id' => '198'],
            'result' => ['success' => true, 'http_status' => 200, 'title' => 'P', 'outcome' => 'ok', 'html' => '<html>'],
            'error' => null,
            'attempts' => 1,
            'completed_at' => '2026-10-28T08:00:00Z',
        ])->assertOk()->assertJson(['received' => true]);

        Event::assertDispatched(JobCompleted::class, function (JobCompleted $e): bool {
            return $e->webhook->clientReferenceId === 'product-42'
                && $e->webhook->metadata === ['feed_id' => '198']
                && $e->webhook->result?->httpStatus === 200
                && $e->webhook->result->outcome === 'ok'
                && $e->webhook->result->html === '<html>';
        });
        Event::assertNotDispatched(JobFailed::class);
    }

    public function test_failed_and_test_deliveries(): void
    {
        Event::fake(self::EVENTS);
        $this->deliver(['event' => 'job.failed', 'job_id' => 'j2', 'result' => null, 'error' => 'blocked: HTTP 403'])->assertOk();
        $this->deliver(['event' => 'webhook.test', 'message' => 'hi'])->assertOk();
        Event::assertDispatched(JobFailed::class, fn (JobFailed $e) => $e->webhook->error === 'blocked: HTTP 403' && $e->webhook->result === null);
        Event::assertDispatched(WebhookTestReceived::class);
    }

    public function test_wrong_secret_or_missing_signature_is_401_and_nothing_dispatched(): void
    {
        Event::fake(self::EVENTS);
        $this->deliver(['event' => 'job.completed', 'job_id' => 'j3'], 'some-other-secret')->assertStatus(401);
        $this->call('POST', '/scrappy/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"event":"job.completed"}')
            ->assertStatus(401);
        foreach (self::EVENTS as $e) {
            Event::assertNotDispatched($e);
        }
    }

    public function test_signed_body_that_is_not_json_is_400(): void
    {
        Event::fake(self::EVENTS);
        $this->deliver([], self::SECRET, 'not json')->assertStatus(400);
        foreach (self::EVENTS as $e) {
            Event::assertNotDispatched($e);
        }
    }

    public function test_verify_uses_the_configured_secret(): void
    {
        $body = '{"event":"webhook.test"}';
        $t = time();
        $header = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, self::SECRET);
        $this->assertTrue(Scrappy::webhooks()->verify($body, $header));
        $this->assertFalse(Scrappy::webhooks()->verify($body.' ', $header));
    }

    public function test_verify_without_any_secret_is_a_config_error(): void
    {
        $scrappy = new \Scrappy\Scrappy('sk_live_test');
        $this->expectException(ScrappyException::class);
        $scrappy->webhooks()->verify('{}', 't=1,v1=00');
    }

    public function test_the_route_is_named(): void
    {
        $this->assertSame(url('/scrappy/webhook'), route('scrappy.webhook'));
    }
}
