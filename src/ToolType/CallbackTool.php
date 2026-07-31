<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use InvalidArgumentException;
use Phore\AiHarness\Client\OpenAI\OpenAiPromptTypeConverter;

readonly class CallbackTool implements ToolType
{
    private mixed $callback;

    private string $name;

    /**
     * Registriert ein PHP-Callable als OpenAI Function Tool.
     *
     * Die Signatur und PHPDoc-Beschreibungen des Callables werden später durch
     * OpenAiPromptTypeConverter mit phore/schema in eine OpenAI-kompatible
     * Function-Tool-Definition umgewandelt.
     *
     * @param callable $callback PHP-Callable, dessen Parameter und Return-Typ per phore/schema geparst werden.
     * @param string|null $name Optionaler OpenAI-Tool-Name. Wenn nicht gesetzt, wird ein Name aus dem Callable abgeleitet.
     * @param string|null $description Optionale Tool-Beschreibung. Wenn nicht gesetzt, wird die PHPDoc-Beschreibung des Callables verwendet.
     * @param bool $strict Ob das OpenAI Function Tool als strict deklariert wird.
     */
    public function __construct(
        callable $callback,
        ?string $name = null,
        public ?string $description = null,
        public bool $strict = true,
    ) {
        $this->callback = $callback;
        $this->name = $this->normalizeName($name ?? $this->deriveName($callback));
    }

    public function callback(): callable
    {
        /** @var callable $callback */
        $callback = $this->callback;
        return $callback;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function available(string $provider = 'open_ai'): bool
    {
        return in_array($this->normalizeProvider($provider), ['open_ai'], true);
    }

    public function type(string $provider = 'open_ai'): string
    {
        if (!$this->available($provider)) {
            throw new InvalidArgumentException('Tool ' . self::class . ' is not available for provider "' . $provider . '".');
        }

        return 'function';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $provider = 'open_ai'): array
    {
        $this->type($provider);

        return (new OpenAiPromptTypeConverter())->convertCallbackTool($this);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name) ?? '';
        $name = trim($name, '_');

        if ($name === '') {
            throw new InvalidArgumentException('Callback tool name must not be empty.');
        }

        return substr($name, 0, 64);
    }

    private function deriveName(callable $callback): string
    {
        if (is_string($callback)) {
            if (str_contains($callback, '::')) {
                return substr($callback, strrpos($callback, '::') + 2);
            }

            return $callback;
        }

        if (is_array($callback) && isset($callback[1]) && is_string($callback[1])) {
            return $callback[1];
        }

        if (is_object($callback) && !$callback instanceof \Closure) {
            $className = str_replace('\\', '_', $callback::class);
            return $className . '_invoke';
        }

        return 'callback_' . substr(sha1(spl_object_hash($callback)), 0, 8);
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $provider = preg_replace('/[^a-z0-9]+/', '_', $provider) ?? '';
        $provider = trim($provider, '_');

        return match ($provider) {
            'openai' => 'open_ai',
            default => $provider,
        };
    }
}
