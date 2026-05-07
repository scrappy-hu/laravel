<?php

declare(strict_types=1);

namespace Scrappy\Http;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use Scrappy\Exceptions\ScrappyException;

/**
 * Thin Guzzle wrapper that knows how to:
 *
 *   - Authenticate every request with the api key.
 *   - Decode JSON responses into arrays.
 *   - Translate non-2xx responses into typed ScrappyException
 *     subclasses via ScrappyException::fromResponse().
 *
 * Retries are NOT done here — the api's idempotency-key support is
 * the contract for that, and forcing a retry on 5xx without an
 * Idempotency-Key risks duplicate jobs. Callers that want retries
 * should pass an idempotencyKey when creating jobs.
 */
final class Client
{
    private readonly Guzzle $guzzle;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        int $timeout = 30,
        ?Guzzle $guzzle = null,
    ) {
        if ($apiKey === '') {
            throw new ScrappyException(
                "Scrappy api_key is empty — set SCRAPPY_API_KEY in your .env or pass it to Scrappy's constructor.",
                'config_error',
            );
        }

        $this->guzzle = $guzzle ?? new Guzzle([
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => $timeout,
            'http_errors' => false, // we surface api errors ourselves with rich types
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'scrappy-hu/laravel '.$this->sdkVersion(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $headers = []): array
    {
        return $this->request('POST', $path, [
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'headers' => $headers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path, []);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $path = ltrim($path, '/');
        try {
            $response = $this->guzzle->request($method, $path, $options);
        } catch (ConnectException | RequestException $e) {
            throw new ScrappyException(
                'Network error talking to '.$this->baseUrl.': '.$e->getMessage(),
                'network_error',
                0,
                [],
                $e,
            );
        } catch (GuzzleException $e) {
            throw new ScrappyException(
                'Unexpected Guzzle error: '.$e->getMessage(),
                'http_error',
                0,
                [],
                $e,
            );
        }

        $status = $response->getStatusCode();
        $rawBody = (string) Utils::copyToString($response->getBody());
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (! is_array($decoded)) {
            // Body wasn't valid JSON — surface a generic error with the
            // raw text so the user can see what came back (rate limiter
            // returns text/plain in some misconfigured setups).
            throw new ScrappyException(
                "Scrappy returned non-JSON body (status {$status}): ".substr($rawBody, 0, 200),
                'malformed_response',
                $status,
            );
        }

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        throw ScrappyException::fromResponse($status, $decoded);
    }

    private function sdkVersion(): string
    {
        // Hard-coded — composer doesn't provide a clean way to read the
        // installed version of the running package itself at runtime.
        // Bump on every release.
        return '0.1.0';
    }
}
