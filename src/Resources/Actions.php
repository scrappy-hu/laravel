<?php

declare(strict_types=1);

namespace Scrappy\Resources;

use Closure;
use Scrappy\Exceptions\ScrappyException;
use Scrappy\Http\Client;
use Scrappy\Models\ActionResult;
use Scrappy\Models\ActionStatus;

/**
 * Action jobs: steps a Scrappy worker runs in a real browser that may
 * change something on a site (log in, add to cart, place an order), at
 * most once.
 *
 *     $result = Scrappy::actions()->run(
 *         spec: $iponOrderSpec,
 *         params: ['product_id' => 123, 'quantity' => 2],
 *         secrets: ['username' => config('services.ipon.username'), 'password' => config('services.ipon.password')],
 *         session: 'xupe-ipon',
 *         actionKey: "xupe-po-{$po->id}",
 *     );
 *
 * Secrets are sealed here, to Scrappy's public key (libsodium sealed
 * box), before anything leaves the process: only Scrappy can open them,
 * they only go to the sealed origins (by default the spec's origin) and
 * Scrappy keeps them only while the job runs. `session` names the
 * browser session to continue (its cookies carry the login over); one
 * job runs per session at a time.
 */
class Actions
{
    /** Longest ?wait= Scrappy holds a request. */
    public const MAX_WAIT_SECONDS = 25;

    /** A running job is given up by Scrappy this long after its timeout. */
    private const LEASE_GRACE_SECONDS = 30;

    /** @var array{key_id: string, public_key: string}|null */
    private ?array $publicKey = null;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  current unix time (tests)
     */
    public function __construct(private readonly Client $http, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Submit and wait for the outcome. Returns a final ActionResult, or
     * one with status Timeout when the job hasn't ended within
     * `waitSeconds` (default: the deadline plus the spec's own timeout,
     * enough for any job that starts in time).
     *
     * With an `actionKey`, calling run() again for the same key never
     * starts a second job while the first is anything but rejected: it
     * waits for (or returns) that one instead.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $secrets  what {{secrets.<name>}} refers to
     * @param  list<string>|null  $origins  where the secrets may go (default: the spec's origin)
     * @param  array<string, mixed>  $options  extra body fields: priority, webhook_url, client_reference_id, metadata
     */
    public function run(
        array $spec,
        array $params = [],
        array $secrets = [],
        ?array $origins = null,
        ?string $session = null,
        ?string $actionKey = null,
        int $deadlineSeconds = 120,
        bool $dryRun = false,
        array $options = [],
        ?int $waitSeconds = null,
    ): ActionResult {
        $budget = $waitSeconds
            ?? $deadlineSeconds + (int) ($spec['timeout_seconds'] ?? 120) + self::LEASE_GRACE_SECONDS;
        $until = ($this->clock)() + max(0, $budget);

        $body = $this->body($spec, $params, $secrets, $origins, $session, $actionKey, $deadlineSeconds, $dryRun, $options);
        try {
            $result = $this->post($body, $secrets, $origins, $spec, $this->waitFor($until));
        } catch (ScrappyException $e) {
            // Same key, an earlier job that wasn't rejected: follow that one.
            $earlier = $e->errorCode() === 'action_key_used' ? ($e->payload()['action'] ?? null) : null;
            if (! is_array($earlier)) {
                throw $e;
            }
            $result = ActionResult::fromArray($earlier);
        }

        while (! $result->status->isFinal() && ($this->clock)() < $until) {
            $result = $this->get($result->id, $this->waitFor($until));
        }

        return $result->status->isFinal() ? $result : $result->asTimeout();
    }

    /**
     * Submit without waiting (or waiting up to `wait` seconds, ≤ 25).
     * Same arguments as run().
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $secrets
     * @param  list<string>|null  $origins
     * @param  array<string, mixed>  $options
     */
    public function submit(
        array $spec,
        array $params = [],
        array $secrets = [],
        ?array $origins = null,
        ?string $session = null,
        ?string $actionKey = null,
        int $deadlineSeconds = 120,
        bool $dryRun = false,
        array $options = [],
        int $wait = 0,
    ): ActionResult {
        $body = $this->body($spec, $params, $secrets, $origins, $session, $actionKey, $deadlineSeconds, $dryRun, $options);

        return $this->post($body, $secrets, $origins, $spec, min(max(0, $wait), self::MAX_WAIT_SECONDS));
    }

    /** The job now; with `wait` (≤ 25 s), as soon as it ends within that. */
    public function get(string $id, int $wait = 0): ActionResult
    {
        $wait = min(max(0, $wait), self::MAX_WAIT_SECONDS);
        $body = $this->http->get("/v1/actions/{$id}", $wait > 0 ? ['wait' => $wait] : [], $this->timeoutFor($wait));

        return ActionResult::fromArray($body);
    }

    /** Wait up to `seconds` for a job to end; Timeout if it didn't. */
    public function wait(string $id, int $seconds): ActionResult
    {
        $until = ($this->clock)() + max(0, $seconds);
        do {
            $result = $this->get($id, $this->waitFor($until));
        } while (! $result->status->isFinal() && ($this->clock)() < $until);

        return $result->status->isFinal() ? $result : $result->asTimeout();
    }

    /** Cancel: a queued job ends as rejected; a running one starts no further mutating step. */
    public function cancel(string $id): ActionResult
    {
        return ActionResult::fromArray($this->http->delete("/v1/actions/{$id}"));
    }

    /** Log a session out: its next job starts with a fresh browser. */
    public function logout(string $session): void
    {
        $this->http->delete('/v1/actions/sessions/'.rawurlencode($session));
    }

    /**
     * Seal secrets for `secrets_sealed`: only Scrappy can open the result.
     *
     * @param  array<string, string>  $secrets
     * @param  list<string>  $origins
     */
    public function seal(array $secrets, array $origins): string
    {
        if (! function_exists('sodium_crypto_box_seal')) {
            throw new ScrappyException('Sealing secrets needs the PHP sodium extension (ext-sodium).', 'config_error');
        }
        $key = base64_decode($this->publicKey()['public_key'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new ScrappyException('Scrappy returned an unusable public key.', 'malformed_response');
        }
        $content = json_encode(['origins' => array_values($origins), 'secrets' => $secrets], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return base64_encode(sodium_crypto_box_seal($content, $key));
    }

    /**
     * The public key secrets are sealed to, fetched once per instance.
     *
     * @return array{key_id: string, public_key: string}
     */
    public function publicKey(bool $refresh = false): array
    {
        if ($this->publicKey === null || $refresh) {
            $body = $this->http->get('/v1/actions/public-key');
            $this->publicKey = ['key_id' => (string) ($body['key_id'] ?? ''), 'public_key' => (string) ($body['public_key'] ?? '')];
        }

        return $this->publicKey;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $secrets
     * @param  list<string>|null  $origins
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function body(
        array $spec,
        array $params,
        array $secrets,
        ?array $origins,
        ?string $session,
        ?string $actionKey,
        int $deadlineSeconds,
        bool $dryRun,
        array $options,
    ): array {
        $body = $options + [
            'spec' => $spec,
            'deadline_seconds' => $deadlineSeconds,
            'dry_run' => $dryRun,
        ];
        if ($params !== []) {
            $body['params'] = $params;
        }
        if ($session !== null) {
            $body['session'] = $session;
        }
        if ($actionKey !== null) {
            $body['action_key'] = $actionKey;
        }
        if ($secrets !== []) {
            $body['secrets_sealed'] = $this->seal($secrets, $origins ?? $this->specOrigins($spec));
        }

        return $body;
    }

    /**
     * POST, sealing again with a fresh key once if Scrappy rotated its key.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $secrets
     * @param  list<string>|null  $origins
     * @param  array<string, mixed>  $spec
     */
    private function post(array $body, array $secrets, ?array $origins, array $spec, int $wait): ActionResult
    {
        $path = '/v1/actions'.($wait > 0 ? '?wait='.$wait : '');
        try {
            return ActionResult::fromArray($this->http->post($path, $body, [], $this->timeoutFor($wait)));
        } catch (ScrappyException $e) {
            if ($secrets === [] || $e->errorCode() !== 'invalid_secrets' || ! str_contains($e->getMessage(), 'cannot be opened')) {
                throw $e;
            }
            $this->publicKey(refresh: true);
            $body['secrets_sealed'] = $this->seal($secrets, $origins ?? $this->specOrigins($spec));

            return ActionResult::fromArray($this->http->post($path, $body, [], $this->timeoutFor($wait)));
        }
    }

    /** @param array<string, mixed> $spec  @return list<string> */
    private function specOrigins(array $spec): array
    {
        $parts = parse_url((string) ($spec['origin'] ?? ''));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new ScrappyException("The spec's origin is not a URL: pass origins for the secrets.", 'invalid_request');
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }

    private function waitFor(int $until): int
    {
        return max(0, min(self::MAX_WAIT_SECONDS, $until - ($this->clock)()));
    }

    /** The HTTP timeout for a request Scrappy may hold `wait` seconds. */
    private function timeoutFor(int $wait): ?int
    {
        return $wait > 0 ? $wait + 15 : null;
    }
}
