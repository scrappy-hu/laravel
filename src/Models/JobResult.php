<?php

declare(strict_types=1);

namespace Scrappy\Models;

/**
 * The output of a completed (or failed) scrape job. Every field is
 * nullable — only what the worker successfully extracted is populated.
 * For example, asking for `extract: ['title']` and not requesting
 * tables means `->tables` stays null.
 */
class JobResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $httpStatus,
        public readonly ?string $finalUrl,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $html,
        public readonly ?string $textContent,
        /** @var list<array<int, string>>|null */
        public readonly ?array $tables,
        public readonly ?int $fetchTimeMs,
        public readonly ?int $ttfbMs,
        public readonly ?int $bodySizeBytes,
        public readonly ?int $redirectCount,
        public readonly ?string $contentType,
        public readonly ?string $errorType,
        public readonly ?string $errorMessage,
        public readonly ?string $screenshotUrl,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            httpStatus: isset($data['http_status']) && is_int($data['http_status']) ? $data['http_status'] : null,
            finalUrl: isset($data['final_url']) && is_string($data['final_url']) ? $data['final_url'] : null,
            title: isset($data['title']) && is_string($data['title']) ? $data['title'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            html: isset($data['html']) && is_string($data['html']) ? $data['html'] : null,
            textContent: isset($data['text_content']) && is_string($data['text_content']) ? $data['text_content'] : null,
            tables: isset($data['tables']) && is_array($data['tables']) ? $data['tables'] : null,
            fetchTimeMs: isset($data['fetch_time_ms']) && is_int($data['fetch_time_ms']) ? $data['fetch_time_ms'] : null,
            ttfbMs: isset($data['ttfb_ms']) && is_int($data['ttfb_ms']) ? $data['ttfb_ms'] : null,
            bodySizeBytes: isset($data['body_size_bytes']) && is_int($data['body_size_bytes']) ? $data['body_size_bytes'] : null,
            redirectCount: isset($data['redirect_count']) && is_int($data['redirect_count']) ? $data['redirect_count'] : null,
            contentType: isset($data['content_type']) && is_string($data['content_type']) ? $data['content_type'] : null,
            errorType: isset($data['error_type']) && is_string($data['error_type']) ? $data['error_type'] : null,
            errorMessage: isset($data['error_message']) && is_string($data['error_message']) ? $data['error_message'] : null,
            screenshotUrl: isset($data['screenshot_url']) && is_string($data['screenshot_url']) ? $data['screenshot_url'] : null,
        );
    }
}
