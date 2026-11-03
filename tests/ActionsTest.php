<?php

declare(strict_types=1);

namespace Scrappy\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Scrappy\Exceptions\ScrappyException;
use Scrappy\Http\Client;
use Scrappy\Models\ActionStatus;
use Scrappy\Resources\Actions;

/**
 * Scrappy::actions(): secrets are sealed to Scrappy's public key before
 * they leave (and open only with its private key), run() waits with
 * ?wait= until the job ends or its budget runs out (Timeout), follows an
 * earlier job for a used action_key, and seals again after a key change.
 */
final class ActionsTest extends TestCase
{
    private string $secretKey;
    private string $publicKey;
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];
    private MockHandler $mock;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $pair = sodium_crypto_box_keypair();
        $this->secretKey = sodium_crypto_box_secretkey($pair);
        $this->publicKey = sodium_crypto_box_publickey($pair);
        $this->mock = new MockHandler();
        $this->history = [];
    }

    private function actions(): Actions
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $http = new Client('sk_live_test', 'https://api.example.test', guzzle: new Guzzle(['handler' => $stack, 'base_uri' => 'https://api.example.test/', 'http_errors' => false]));

        return new Actions($http, fn (): int => $this->now);
    }

    /** @param array<string, mixed> $body */
    private function reply(int $status, array $body, int $advance = 0): void
    {
        $this->mock->append(function () use ($status, $body, $advance) {
            $this->now += $advance;

            return new Response($status, [], (string) json_encode($body));
        });
    }

    private function key(?string $public = null): void
    {
        $this->reply(200, ['key_id' => 'v1', 'public_key' => base64_encode($public ?? $this->publicKey), 'algorithm' => 'x25519-xsalsa20poly1305-sealedbox']);
    }

    /** @return array<string, mixed> */
    private function action(string $status, array $extra = []): array
    {
        return $extra + ['action_id' => 'a1', 'status' => $status, 'mutation_started' => false];
    }

    /** @return array<string, mixed> */
    private function sentBody(int $n): array
    {
        return json_decode((string) $this->history[$n]['request']->getBody(), true);
    }

    private const SPEC = [
        'version' => 1,
        'origin' => 'https://ipon.hu',
        'timeout_seconds' => 150,
        'steps' => [['id' => 'cart', 'type' => 'fetch', 'url' => '/api/cart', 'save_as' => 'cart']],
    ];

    public function test_run_seals_the_secrets_and_waits_until_the_job_ends(): void
    {
        $this->key();
        $this->reply(202, $this->action('queued'), advance: 25);
        $this->reply(200, $this->action('running', ['mutation_started' => true]), advance: 25);
        $this->reply(200, $this->action('succeeded', ['mutation_started' => true, 'output' => ['order_id' => '777']]), advance: 3);

        $result = $this->actions()->run(
            spec: self::SPEC,
            params: ['product_id' => 5],
            secrets: ['username' => 'buyer@example.com', 'password' => 'p@ss "árvíz"'],
            session: 'xupe-ipon',
            actionKey: 'xupe-po-1',
        );

        $this->assertSame(ActionStatus::Succeeded, $result->status);
        $this->assertSame(['order_id' => '777'], $result->output);
        $this->assertFalse($result->safeToRetry());

        $this->assertSame('/v1/actions/public-key', $this->history[0]['request']->getUri()->getPath());
        $post = $this->history[1]['request'];
        $this->assertSame('POST', $post->getMethod());
        $this->assertSame('wait=25', $post->getUri()->getQuery());
        $body = $this->sentBody(1);
        $this->assertSame('xupe-ipon', $body['session']);
        $this->assertSame('xupe-po-1', $body['action_key']);
        $this->assertSame(['product_id' => 5], $body['params']);
        $this->assertArrayNotHasKey('secrets', $body);
        $this->assertStringNotContainsString('p@ss', (string) $post->getBody());

        // Only the private key opens it; inside: the secrets and the spec's origin.
        $opened = sodium_crypto_box_seal_open(base64_decode($body['secrets_sealed']), sodium_crypto_box_keypair_from_secretkey_and_publickey($this->secretKey, $this->publicKey));
        $this->assertSame(
            ['origins' => ['https://ipon.hu'], 'secrets' => ['username' => 'buyer@example.com', 'password' => 'p@ss "árvíz"']],
            json_decode((string) $opened, true),
        );

        $this->assertSame('/v1/actions/a1', $this->history[2]['request']->getUri()->getPath());
        $this->assertSame('wait=25', $this->history[2]['request']->getUri()->getQuery());
        $this->assertCount(4, $this->history);
    }

    public function test_run_gives_timeout_with_mutation_started_when_the_budget_runs_out(): void
    {
        $this->reply(202, $this->action('queued'), advance: 25);
        $this->reply(200, $this->action('running', ['mutation_started' => true]), advance: 25);

        $result = $this->actions()->run(spec: self::SPEC, waitSeconds: 40);

        $this->assertSame(ActionStatus::Timeout, $result->status);
        $this->assertTrue($result->mutationStarted);
        $this->assertTrue($result->needsVerification());
        // The last wait only asks for what is left of the budget.
        $this->assertSame('wait=15', $this->history[1]['request']->getUri()->getQuery());
        $this->assertCount(2, $this->history);
    }

    public function test_a_used_action_key_follows_the_earlier_job_instead_of_starting_one(): void
    {
        $this->reply(409, ['error' => 'action_key_used', 'message' => 'used', 'action' => $this->action('running', ['action_id' => 'a0'])]);
        $this->reply(200, $this->action('uncertain', ['action_id' => 'a0', 'mutation_started' => true, 'reason' => 'worker_lost']), advance: 5);

        $result = $this->actions()->run(spec: self::SPEC, actionKey: 'xupe-po-1');

        $this->assertSame('a0', $result->id);
        $this->assertSame(ActionStatus::Uncertain, $result->status);
        $this->assertTrue($result->needsVerification());
        $this->assertSame('/v1/actions/a0', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_a_rotated_key_is_fetched_again_and_the_secrets_sealed_again(): void
    {
        $old = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
        $this->key($old);
        $this->reply(400, ['error' => 'invalid_secrets', 'message' => 'secrets_sealed: cannot be opened with any current Scrappy key']);
        $this->key();
        $this->reply(200, $this->action('rejected', ['reason' => 'price_too_high']));

        $result = $this->actions()->run(spec: self::SPEC, secrets: ['password' => 'secret-1']);

        $this->assertSame(ActionStatus::Rejected, $result->status);
        $this->assertTrue($result->safeToRetry());
        $resent = $this->sentBody(3);
        $opened = sodium_crypto_box_seal_open(base64_decode($resent['secrets_sealed']), sodium_crypto_box_keypair_from_secretkey_and_publickey($this->secretKey, $this->publicKey));
        $this->assertSame('secret-1', json_decode((string) $opened, true)['secrets']['password']);
    }

    public function test_other_errors_are_thrown(): void
    {
        $this->reply(400, ['error' => 'validation_error', 'message' => 'spec: invalid', 'issues' => [['path' => 'steps.0', 'message' => 'needs confirm']]]);

        try {
            $this->actions()->run(spec: self::SPEC);
            $this->fail('expected an exception');
        } catch (ScrappyException $e) {
            $this->assertSame('validation_error', $e->errorCode());
            $this->assertSame('needs confirm', $e->payload()['issues'][0]['message']);
        }
    }

    public function test_dry_run_and_explicit_origins_go_through(): void
    {
        $this->key();
        $this->reply(200, $this->action('rejected', ['reason' => 'dry_run', 'dry_run' => true, 'dry_run_result' => ['step' => 'add_to_cart', 'found' => true]]));

        $result = $this->actions()->submit(spec: self::SPEC, secrets: ['password' => 'x1234'], origins: ['https://ipon.hu', 'https://login.ipon.hu'], dryRun: true, wait: 99);

        $this->assertTrue($result->dryRun);
        $this->assertSame('add_to_cart', $result->dryRunResult['step']);
        $this->assertTrue($this->sentBody(1)['dry_run']);
        $this->assertSame('wait=25', $this->history[1]['request']->getUri()->getQuery());
        $opened = sodium_crypto_box_seal_open(base64_decode($this->sentBody(1)['secrets_sealed']), sodium_crypto_box_keypair_from_secretkey_and_publickey($this->secretKey, $this->publicKey));
        $this->assertSame(['https://ipon.hu', 'https://login.ipon.hu'], json_decode((string) $opened, true)['origins']);
    }

    public function test_logout_and_cancel(): void
    {
        $this->reply(200, ['session' => 'xupe-ipon', 'logged_out' => true]);
        $this->reply(200, $this->action('rejected', ['reason' => 'not_started']));
        $actions = $this->actions();

        $actions->logout('xupe-ipon');
        $this->assertSame(ActionStatus::Rejected, $actions->cancel('a1')->status);
        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertSame('/v1/actions/sessions/xupe-ipon', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/v1/actions/a1', $this->history[1]['request']->getUri()->getPath());
    }
}
