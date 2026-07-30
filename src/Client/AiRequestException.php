<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

use RuntimeException;
use Throwable;

/**
 * Thrown when a request to the OpenAI Responses API cannot be completed successfully.
 */
final class AiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?AiResponse $response = null,
        public readonly ?string $curlError = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $response?->statusCode ?? 0, $previous);
    }

    public static function fromCurlError(string $message, string $curlError): self
    {
        return new self($message . ': ' . $curlError, null, $curlError);
    }

    public static function fromResponse(AiResponse $response): self
    {
        $message = $response->getErrorMessage();
        if ($message === null || $message === '') {
            $message = 'OpenAI request failed with HTTP status ' . $response->statusCode . '.';
        }

        return new self($message, $response);
    }
}
