<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

use Phore\AiHarness\Client\AiResponse;

/** @internal Mutable counters belong to one invocation, never to the facade. */
final class RunContext
{
    public int $requests = 0;
    public int $toolCalls = 0;
    public int $errors = 0;
    public int $retries = 0;
    public float $durationApi = 0;
    public float $durationTools = 0;
    public bool $retryPending = false;
    private array $tokens = ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null];
    private readonly int $started;
    private readonly string $id;
    private bool $finished = false;
    private string $textBuffer = '';
    private \WeakMap $countedErrors;

    public function __construct(private readonly LoggerInterface $logger, private readonly string $model)
    {
        $this->started = hrtime(true);
        $this->id = bin2hex(random_bytes(8));
        $this->countedErrors = new \WeakMap();
    }

    public function emit(string $type, array $context = [], string $message = '', string $level = 'debug', ?RunStatistics $statistics = null): void
    {
        try {
            $this->logger->log(new LogEvent(
                Redactor::text($this->model), $type, $level, Redactor::text($message),
                Redactor::value($context), $statistics, $this->id,
            ));
        } catch (\Throwable) {
            // Diagnostic adapters must not change a result or mask the original exception.
        }
    }

    /** Buffer before redacting so a credential split across SSE chunks cannot escape. */
    public function text(string $delta): void
    {
        $this->textBuffer .= $delta;
        while (($end = strpos($this->textBuffer, "\n")) !== false) {
            $line = substr($this->textBuffer, 0, $end + 1);
            $this->textBuffer = substr($this->textBuffer, $end + 1);
            $this->emit('text_delta', message: $line);
        }
    }

    public function flushText(): void
    {
        if ($this->textBuffer !== '') {
            $line = $this->textBuffer;
            $this->textBuffer = '';
            $this->emit('text_delta', message: $line);
        }
        $this->emit('response_end');
    }

    public function addResponse(AiResponse $response): void
    {
        foreach ($this->tokens as $key => $total) {
            $value = $response->body['usage'][$key] ?? null;
            if (is_int($value)) {
                $this->tokens[$key] = ($total ?? 0) + $value;
            }
        }
    }

    public function error(\Throwable $error, array $context = [], string $message = 'Run failed; exception propagated.', bool $deduplicate = true): void
    {
        if ($deduplicate && isset($this->countedErrors[$error])) {
            return;
        }
        if ($deduplicate) {
            $this->countedErrors[$error] = true;
        }
        $this->errors++;
        // Exception messages may contain arbitrary credentials or full request bodies.
        $this->emit('warning', [...$context, 'error_class' => $error::class, 'retry' => $this->retries], $message, 'warning');
    }

    public function finish(string $status): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $this->flushText();
        $this->emit('stats', statistics: new RunStatistics(
            $status, $this->requests, $this->toolCalls, $this->errors, $this->retries,
            $this->tokens['input_tokens'], $this->tokens['output_tokens'], $this->tokens['total_tokens'],
            (hrtime(true) - $this->started) / 1e9, $this->durationApi, $this->durationTools,
        ));
    }
}
