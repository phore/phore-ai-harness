<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

use CurlHandle;
use RuntimeException;

/**
 * CURL based client for the OpenAI Responses API.
 */
final class OpenAiClient
{
    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $organization = null,
        private readonly ?string $project = null,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $timeout = 120,
        private readonly int $connectTimeout = 10,
        private readonly array $defaultHeaders = [],
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required.');
        }
    }

    public static function fromEnvironment(): self
    {
        $apiKey = getenv('OPENAI_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Environment variable OPENAI_API_KEY is not set.');
        }

        $organization = getenv('OPENAI_ORGANIZATION');
        $project = getenv('OPENAI_PROJECT');

        return new self(
            apiKey: $apiKey,
            organization: is_string($organization) && $organization !== '' ? $organization : null,
            project: is_string($project) && $project !== '' ? $project : null,
        );
    }

    /**
     * Sends a non-streaming request to POST /v1/responses.
     */
    public function createResponse(AiRequest $request): AiResponse
    {
        $headers = [];
        $curl = $this->createCurlHandle($request, false, $headers);

        try {
            $rawBody = curl_exec($curl);
            if ($rawBody === false) {
                throw AiRequestException::fromCurlError('OpenAI request failed', curl_error($curl));
            }

            $response = $this->buildJsonResponse($curl, $headers, $rawBody);
            $this->assertSuccessfulResponse($response);

            return $response;
        } finally {
            unset($curl);
        }
    }

    /**
     * Sends a streaming request to POST /v1/responses.
     *
     * The callback receives every parsed Server-Sent-Event payload as array.
     */
    public function streamResponse(AiRequest $request, callable $onEvent): AiResponse
    {
        $context = $this->createStreamContext();
        $curl = $this->createCurlHandle(
            $request,
            true,
            $context->headers,
            $this->createStreamWriteFunction($context, $onEvent),
        );

        try {
            $result = curl_exec($curl);
            if ($result === false) {
                throw AiRequestException::fromCurlError('OpenAI streaming request failed', curl_error($curl));
            }

            $this->flushStreamBuffer($context, $onEvent);

            $response = $this->buildStreamResponse($curl, $context);
            $this->assertSuccessfulResponse($response);

            return $response;
        } finally {
            unset($curl);
        }
    }

    /**
     * @internal Public so AiRequestSpooler can reuse the exact client setup.
     *
     * @param array<string, list<string>> $responseHeaders
     */
    public function createCurlHandle(
        AiRequest $request,
        bool $stream,
        array &$responseHeaders,
        ?callable $writeFunction = null,
    ): CurlHandle {
        $curl = curl_init($this->baseUrl . '/responses');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        $headers = $this->buildRequestHeaders($stream);
        $responseHeaders = [];

        $options = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($request->toArray($stream), JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $curl, string $line) use (&$responseHeaders): int {
                $this->parseHeaderLine($responseHeaders, $line);
                return strlen($line);
            },
        ];

        if ($writeFunction !== null) {
            $options[CURLOPT_WRITEFUNCTION] = $writeFunction;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        curl_setopt_array($curl, $options);

        return $curl;
    }

    /**
     * @internal
     */
    public function createStreamContext(): object
    {
        return (object) [
            'headers' => [],
            'rawBody' => '',
            'events' => [],
            'completedBody' => null,
            'sseBuffer' => '',
            'outputText' => '',
        ];
    }

    /**
     * @internal
     */
    public function createStreamWriteFunction(object $context, ?callable $onEvent = null): callable
    {
        return function (CurlHandle $curl, string $chunk) use ($context, $onEvent): int {
            $context->rawBody .= $chunk;
            $context->sseBuffer .= $chunk;
            $this->consumeStreamBuffer($context, $onEvent);

            return strlen($chunk);
        };
    }

    /**
     * @internal
     *
     * @param array<string, list<string>> $headers
     */
    public function buildJsonResponse(CurlHandle $curl, array $headers, string $rawBody): AiResponse
    {
        return AiResponse::fromJson((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $headers, $rawBody);
    }

    /**
     * @internal
     */
    public function buildStreamResponse(CurlHandle $curl, object $context): AiResponse
    {
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $body = is_array($context->completedBody) ? $context->completedBody : $this->decodeJsonBody($context->rawBody);

        if ($body === [] && $context->outputText !== '') {
            $body = ['output_text' => $context->outputText, 'status' => 'completed'];
        }

        return new AiResponse($statusCode, $context->headers, $body, $context->rawBody, $context->events);
    }

    /**
     * @internal
     */
    public function assertSuccessfulResponse(AiResponse $response): void
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw AiRequestException::fromResponse($response);
        }
    }

    /**
     * @return list<string>
     */
    private function buildRequestHeaders(bool $stream): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
        ];

        if ($this->organization !== null) {
            $headers[] = 'OpenAI-Organization: ' . $this->organization;
        }
        if ($this->project !== null) {
            $headers[] = 'OpenAI-Project: ' . $this->project;
        }

        foreach ($this->defaultHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function parseHeaderLine(array &$headers, string $line): void
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'HTTP/')) {
            return;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return;
        }

        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        $headers[$name] ??= [];
        $headers[$name][] = $value;
    }

    private function consumeStreamBuffer(object $context, ?callable $onEvent): void
    {
        while (true) {
            $separator = $this->findSseSeparator($context->sseBuffer);
            if ($separator === null) {
                return;
            }

            [$position, $length] = $separator;
            $frame = substr($context->sseBuffer, 0, $position);
            $context->sseBuffer = substr($context->sseBuffer, $position + $length);
            $this->handleSseFrame($context, $frame, $onEvent);
        }
    }

    /**
     * @return array{0:int, 1:int}|null
     */
    private function findSseSeparator(string $buffer): ?array
    {
        $lf = strpos($buffer, "\n\n");
        $crlf = strpos($buffer, "\r\n\r\n");

        if ($lf === false && $crlf === false) {
            return null;
        }
        if ($lf === false) {
            return [$crlf, 4];
        }
        if ($crlf === false) {
            return [$lf, 2];
        }

        return $crlf < $lf ? [$crlf, 4] : [$lf, 2];
    }

    /**
     * @internal
     */
    public function flushStreamBuffer(object $context, ?callable $onEvent = null): void
    {
        $frame = trim($context->sseBuffer);
        if ($frame === '') {
            $context->sseBuffer = '';
            return;
        }
        $context->sseBuffer = '';
        $this->handleSseFrame($context, $frame, $onEvent);
    }

    private function handleSseFrame(object $context, string $frame, ?callable $onEvent): void
    {
        $dataLines = [];
        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return;
        }

        $data = implode("\n", $dataLines);
        if ($data === '[DONE]') {
            return;
        }

        $event = json_decode($data, true);
        if (!is_array($event)) {
            return;
        }

        $context->events[] = $event;
        $this->updateStreamSummary($context, $event);

        if ($onEvent !== null) {
            $onEvent($event);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function updateStreamSummary(object $context, array $event): void
    {
        if (($event['type'] ?? null) === 'response.output_text.delta' && isset($event['delta']) && is_string($event['delta'])) {
            $context->outputText .= $event['delta'];
        }

        if (($event['type'] ?? null) === 'response.completed' && isset($event['response']) && is_array($event['response'])) {
            $context->completedBody = $event['response'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
