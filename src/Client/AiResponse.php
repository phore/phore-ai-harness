<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

/**
 * Value object for a response returned by the OpenAI Responses API.
 */
final readonly class AiResponse
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $body
     * @param array<int, array<string, mixed>> $streamEvents
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public array $body,
        public string $rawBody,
        public array $streamEvents = [],
    ) {
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function fromJson(int $statusCode, array $headers, string $rawBody): self
    {
        $body = [];
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($statusCode, $headers, $body, $rawBody);
    }

    public function getId(): ?string
    {
        return isset($this->body['id']) && is_string($this->body['id']) ? $this->body['id'] : null;
    }

    public function getStatus(): ?string
    {
        return isset($this->body['status']) && is_string($this->body['status']) ? $this->body['status'] : null;
    }

    public function isCompleted(): bool
    {
        return $this->getStatus() === 'completed';
    }

    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);
        return isset($this->headers[$key]) ? implode(', ', $this->headers[$key]) : null;
    }

    public function getOutputText(): string
    {
        if (isset($this->body['output_text']) && is_string($this->body['output_text'])) {
            return $this->body['output_text'];
        }

        $text = '';
        $output = $this->body['output'] ?? null;
        if (!is_array($output)) {
            return $text;
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                if (isset($contentItem['text']) && is_string($contentItem['text'])) {
                    $text .= $contentItem['text'];
                }
            }
        }

        return $text;
    }

    public function getErrorMessage(): ?string
    {
        $error = $this->body['error'] ?? null;
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }
        if (isset($this->body['message']) && is_string($this->body['message'])) {
            return $this->body['message'];
        }

        return null;
    }
}
