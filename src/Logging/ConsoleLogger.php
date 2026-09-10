<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

use InvalidArgumentException;

final class ConsoleLogger implements LoggerInterface
{
    private $stream;
    private array $buffers = [];

    /** @param resource|null $stream Defaults to STDERR; an injected stream is not closed. */
    public function __construct($stream = null)
    {
        $stream ??= defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('ConsoleLogger requires a writable stream.');
        }
        $this->stream = $stream;
    }

    public function log(LogEvent $event): void
    {
        $key = $event->runId . ':' . $event->model;
        if ($event->type === 'text_delta') {
            $this->buffers[$key] = ($this->buffers[$key] ?? '') . $event->message;
            while (($end = strpos($this->buffers[$key], "\n")) !== false) {
                $this->line($event, substr($this->buffers[$key], 0, $end));
                $this->buffers[$key] = substr($this->buffers[$key], $end + 1);
            }
            return;
        }

        if (($this->buffers[$key] ?? '') !== '') {
            $this->line($event, $this->buffers[$key]);
        }
        unset($this->buffers[$key]);
        if ($event->type === 'response_end') {
            return;
        }

        $parts = [];
        $fields = $event->statistics !== null ? get_object_vars($event->statistics) : $event->context;
        foreach ($fields as $name => $value) {
            $formatted = $value === null ? 'n/a' : (is_scalar($value) ? (string) $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
            if (is_float($value)) {
                $formatted = sprintf('%.3fs', $value);
            }
            $parts[] = $name . '=' . $formatted;
        }
        $label = $event->level === 'warning' ? 'WARNING' : strtoupper($event->type);
        $this->line($event, trim($label . ' ' . $event->message . ' ' . implode(' ', $parts)), $event->level === 'warning');
    }

    private function line(LogEvent $event, string $text, bool $warning = false): void
    {
        $prefix = '[' . str_replace("\n", ' ', Redactor::text($event->model)) . '] ';
        $color = $warning && getenv('NO_COLOR') === false && getenv('TERM') !== 'dumb'
            && function_exists('stream_isatty') && stream_isatty($this->stream);
        foreach (explode("\n", Redactor::text($text)) as $line) {
            fwrite($this->stream, ($color ? "\033[31m" : '') . $prefix . $line . ($color ? "\033[0m" : '') . "\n");
        }
    }
}
