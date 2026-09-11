<?php

declare(strict_types=1);

namespace Phore\AiHarness\Client;

use CurlHandle;
use RuntimeException;
use Throwable;
use Phore\AiHarness\Usage\GlobalUsage;

/**
 * Executes multiple OpenAI Responses API requests concurrently via curl_multi.
 */
final class AiRequestSpooler
{
    /**
     * @var array<int, array{request: AiRequest, stream: bool, onEvent: callable|null, onResponse: callable|null, onError: callable|null}>
     */
    private array $queue = [];

    public function __construct(private readonly OpenAiClient $client)
    {
    }

    public function add(AiRequest $request, ?callable $onResponse = null, ?callable $onError = null): self
    {
        $this->queue[] = [
            'request' => $request,
            'stream' => false,
            'onEvent' => null,
            'onResponse' => $onResponse,
            'onError' => $onError,
        ];

        return $this;
    }

    public function addStream(
        AiRequest $request,
        callable $onEvent,
        ?callable $onResponse = null,
        ?callable $onError = null,
    ): self {
        $this->queue[] = [
            'request' => $request,
            'stream' => true,
            'onEvent' => $onEvent,
            'onResponse' => $onResponse,
            'onError' => $onError,
        ];

        return $this;
    }

    /**
     * Runs all queued requests concurrently and clears the queue afterwards.
     *
     * @return array<int, AiResponse> Responses keyed by insertion index.
     */
    public function run(): array
    {
        if ($this->queue === []) {
            return [];
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            throw new RuntimeException('Could not initialize curl_multi.');
        }

        $contexts = [];
        $responses = [];
        $firstUnhandledError = null;

        try {
            foreach ($this->queue as $index => $entry) {
                $context = $this->createContext($index, $entry);
                try {
                    $curl = $this->createHandleForContext($context);
                } catch (Throwable $error) {
                    GlobalUsage::instance()->finish($context->usageCall, failed: true);
                    throw $error;
                }
                $id = (int) $curl;
                $context->curl = $curl;
                $contexts[$id] = $context;
                curl_multi_add_handle($multi, $curl);
            }

            $this->executeMultiHandle($multi);

            while ($info = curl_multi_info_read($multi)) {
                /** @var CurlHandle $curl */
                $curl = $info['handle'];
                $id = (int) $curl;
                $context = $contexts[$id] ?? null;
                if ($context === null) {
                    continue;
                }

                $response = null;
                $failed = true;
                try {
                    try {
                        if (($info['result'] ?? CURLE_OK) !== CURLE_OK) {
                            throw AiRequestException::fromCurlError('OpenAI request failed', curl_error($curl));
                        }

                        $response = $this->responseFromContext($curl, $context);
                        $this->client->assertSuccessfulResponse($response);
                        $responses[$context->index] = $response;
                        $failed = $context->stream && ($context->streamContext->failed || $context->streamContext->completedBody === null);
                    } finally {
                        GlobalUsage::instance()->finish(
                            $context->usageCall, $response?->body ?? $context->streamContext?->completedBody, $failed,
                        );
                    }

                    if ($context->onResponse !== null) {
                        ($context->onResponse)($response, $context->index);
                    }
                } catch (Throwable $error) {
                    if ($context->onError !== null) {
                        ($context->onError)($error, $context->index);
                    } else {
                        $firstUnhandledError ??= $error;
                    }
                } finally {
                    curl_multi_remove_handle($multi, $curl);
                    unset($contexts[$id], $curl);
                }
            }
        } finally {
            foreach ($contexts as $context) {
                // Other handles may already have completed when a user callback throws.
                $body = $context->streamContext?->completedBody;
                $failed = true;
                try {
                    if ($context->stream) {
                        $this->client->flushStreamBuffer($context->streamContext);
                        $response = $this->client->buildStreamResponse($context->curl, $context->streamContext);
                    } else {
                        $raw = curl_multi_getcontent($context->curl);
                        $response = $this->client->buildJsonResponse($context->curl, $context->headers, is_string($raw) ? $raw : '');
                    }
                    $body = $response->body;
                    $failed = curl_errno($context->curl) !== CURLE_OK
                        || $response->statusCode < 200 || $response->statusCode >= 300
                        || ($context->stream && ($context->streamContext->failed || $context->streamContext->completedBody === null));
                } catch (Throwable) {
                    // Preserve the original exception while accounting for available usage.
                }
                GlobalUsage::instance()->finish($context->usageCall, $body, $failed);
                curl_multi_remove_handle($multi, $context->curl);
            }
            $this->queue = [];
            curl_multi_close($multi);
        }

        if ($firstUnhandledError !== null) {
            throw $firstUnhandledError;
        }

        ksort($responses);
        return $responses;
    }

    /**
     * @param array{request: AiRequest, stream: bool, onEvent: callable|null, onResponse: callable|null, onError: callable|null} $entry
     */
    private function createContext(int $index, array $entry): object
    {
        $streamContext = $entry['stream'] ? $this->client->createStreamContext() : null;

        return (object) [
            'usageCall' => GlobalUsage::instance()->begin((string) ($entry['request']->extraBody['model'] ?? $entry['request']->model)),
            'index' => $index,
            'request' => $entry['request'],
            'stream' => $entry['stream'],
            'onEvent' => $entry['onEvent'],
            'onResponse' => $entry['onResponse'],
            'onError' => $entry['onError'],
            'headers' => [],
            'rawBody' => '',
            'streamContext' => $streamContext,
            'curl' => null,
        ];
    }

    private function createHandleForContext(object $context): CurlHandle
    {
        if ($context->stream) {
            return $this->client->createCurlHandle(
                $context->request,
                true,
                $context->streamContext->headers,
                $this->client->createStreamWriteFunction($context->streamContext, $context->onEvent),
            );
        }

        return $this->client->createCurlHandle($context->request, false, $context->headers);
    }

    private function executeMultiHandle(\CurlMultiHandle $multi): void
    {
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        if ($status !== CURLM_OK) {
            throw new AiRequestException('curl_multi failed with status ' . $status . '.');
        }
    }

    private function responseFromContext(CurlHandle $curl, object $context): AiResponse
    {
        if ($context->stream) {
            $this->client->flushStreamBuffer($context->streamContext, $context->onEvent);
            return $this->client->buildStreamResponse($curl, $context->streamContext);
        }

        $rawBody = curl_multi_getcontent($curl);
        return $this->client->buildJsonResponse($curl, $context->headers, is_string($rawBody) ? $rawBody : '');
    }
}
