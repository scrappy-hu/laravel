<?php

declare(strict_types=1);

namespace Scrappy\Tests;

use PHPUnit\Framework\TestCase;
use Scrappy\Webhooks\SignatureVerifier;

/**
 * The signature verifier is the only crypto-sensitive code in the
 * SDK. A bug here = customers accept forged webhooks → bad. Cover:
 *
 *   - happy path with a freshly signed body
 *   - replay defence (old timestamp rejected)
 *   - clock skew tolerance (small future drift accepted)
 *   - tampered body rejected
 *   - tampered signature rejected
 *   - wrong secret rejected
 *   - malformed header rejected
 *   - missing header rejected
 *   - constant-time compare (length mismatch handled)
 */
final class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'a1b2c3d4e5f67890a1b2c3d4e5f67890';

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SignatureVerifier(replayWindowSeconds: 300);
    }

    public function test_accepts_a_freshly_signed_body(): void
    {
        $now = 1746381057;
        $body = '{"event":"job.completed","job_id":"abc"}';
        $header = $this->sign($body, $now, self::SECRET);

        $this->assertTrue($this->verifier->verify($body, $header, self::SECRET, $now));
    }

    public function test_rejects_a_replay_older_than_the_window(): void
    {
        $signed = 1746381057;
        $now = $signed + 301; // 1 second past the 5-minute window
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $signed, self::SECRET);

        $this->assertFalse($this->verifier->verify($body, $header, self::SECRET, $now));
    }

    public function test_accepts_signature_just_inside_the_window(): void
    {
        $signed = 1746381057;
        $now = $signed + 299;
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $signed, self::SECRET);

        $this->assertTrue($this->verifier->verify($body, $header, self::SECRET, $now));
    }

    public function test_tolerates_small_clock_skew_into_the_future(): void
    {
        // Server's clock is 30s ahead of ours — header timestamp lands
        // a bit in the future from our perspective. Accepted up to 60s.
        $signed = 1746381057;
        $now = $signed - 30;
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $signed, self::SECRET);

        $this->assertTrue($this->verifier->verify($body, $header, self::SECRET, $now));
    }

    public function test_rejects_signature_too_far_in_the_future(): void
    {
        // 5 minutes ahead of our clock — almost certainly a forgery.
        $signed = 1746381057;
        $now = $signed - 300;
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $signed, self::SECRET);

        $this->assertFalse($this->verifier->verify($body, $header, self::SECRET, $now));
    }

    public function test_rejects_tampered_body(): void
    {
        $now = 1746381057;
        $original = '{"event":"job.completed","status":"completed"}';
        $tampered = '{"event":"job.completed","status":"failed"}';
        $header = $this->sign($original, $now, self::SECRET);

        $this->assertFalse($this->verifier->verify($tampered, $header, self::SECRET, $now));
    }

    public function test_rejects_tampered_signature(): void
    {
        $now = 1746381057;
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $now, self::SECRET);
        // Flip the last hex char of the v1 signature.
        $tampered = preg_replace_callback('/v1=([0-9a-f]+)$/', function ($m) {
            $sig = $m[1];

            return 'v1='.substr($sig, 0, -1).($sig[-1] === '0' ? '1' : '0');
        }, (string) $header);
        $this->assertNotSame($header, $tampered);

        $this->assertFalse($this->verifier->verify($body, $tampered, self::SECRET, $now));
    }

    public function test_rejects_wrong_secret(): void
    {
        $now = 1746381057;
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $now, self::SECRET);

        $this->assertFalse($this->verifier->verify($body, $header, 'wrong-secret', $now));
    }

    public function test_rejects_missing_header(): void
    {
        $this->assertFalse($this->verifier->verify('{}', null, self::SECRET));
    }

    public function test_rejects_empty_header(): void
    {
        $this->assertFalse($this->verifier->verify('{}', '', self::SECRET));
    }

    public function test_rejects_malformed_header_no_t(): void
    {
        $this->assertFalse(
            $this->verifier->verify('{}', 'v1=abc', self::SECRET, 1746381057),
        );
    }

    public function test_rejects_malformed_header_no_v1(): void
    {
        $this->assertFalse(
            $this->verifier->verify('{}', 't=1746381057', self::SECRET, 1746381057),
        );
    }

    public function test_rejects_non_numeric_timestamp(): void
    {
        $this->assertFalse(
            $this->verifier->verify('{}', 't=foo,v1=abc', self::SECRET, 1746381057),
        );
    }

    public function test_rejects_empty_secret(): void
    {
        $now = 1746381057;
        $body = '{"event":"job.completed"}';
        $header = $this->sign($body, $now, self::SECRET);

        $this->assertFalse($this->verifier->verify($body, $header, '', $now));
    }

    public function test_handles_signature_length_mismatch_safely(): void
    {
        // Truncated v1 — would crash a naive `===` compare with strlen mismatch.
        $now = 1746381057;
        $body = '{"event":"job.completed"}';
        $shortHeader = "t={$now},v1=abc";

        $this->assertFalse($this->verifier->verify($body, $shortHeader, self::SECRET, $now));
    }

    public function test_supports_unicode_body(): void
    {
        $now = 1746381057;
        $body = '{"title":"árvíztűrő tükörfúrógép"}';
        $header = $this->sign($body, $now, self::SECRET);

        $this->assertTrue($this->verifier->verify($body, $header, self::SECRET, $now));
    }

    /**
     * Helper that builds an X-Scrappy-Signature header for a body the
     * way the api itself does. Used by tests to pretend they're the
     * api emitting a webhook.
     */
    private function sign(string $body, int $timestamp, string $secret): string
    {
        $sig = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return "t={$timestamp},v1={$sig}";
    }
}
