<?php

declare(strict_types=1);

namespace Phore\AiHarness\Helper;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\PhoreAi;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\TextPrompt;
use Phore\AiHarness\ToolType\ToolType;
use ReflectionClass;
use RuntimeException;

final class Toolkit
{
    private function __construct()
    {
    }

    /**
     * Encodes data as JSON using the project's default flags.
     *
     * @throws JsonException
     */
    public static function jsonEncode(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    /**
     * Decodes a JSON object from raw model output.
     *
     * Markdown fenced JSON blocks are accepted and stripped before decoding.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public static function decodeJsonOutput(string $output): array
    {
        $json = trim($output);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Expected JSON object output for casting.');
        }

        return $decoded;
    }

    public static function appendInstructions(?string $instructions, string $addition): string
    {
        if ($instructions === null || $instructions === '') {
            return $addition;
        }

        return $instructions . "\n\n" . $addition;
    }

    /**
     * Creates an OpenAI-compatible schema name from a class name.
     *
     * @param class-string $className
     */
    public static function schemaName(string $className): string
    {
        $shortName = (new ReflectionClass($className))->getShortName();
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $shortName) ?: 'response';

        return substr($name, 0, 64);
    }

    /**
     * Creates a configured `PhoreAi` facade from common function options.
     *
     * @param array{client?: OpenAiClient|string|null, model?: string, timeout?: int, connect_timeout?: int} $options
     */
    public static function createAi(array $options = []): PhoreAi
    {
        $client = $options['client'] ?? null;

        if (!$client instanceof OpenAiClient && (isset($options['timeout']) || isset($options['connect_timeout']))) {
            $client = self::createOpenAiClientWithTimeouts(
                is_string($client) ? $client : null,
                $options['timeout'] ?? OpenAiClient::DEFAULT_TIMEOUT,
                $options['connect_timeout'] ?? OpenAiClient::DEFAULT_CONNECT_TIMEOUT,
            );
        }

        $ai = new PhoreAi($client);

        if (isset($options['model'])) {
            $ai = $ai->withModel($options['model']);
        }

        return $ai;
    }

    private static function createOpenAiClientWithTimeouts(?string $dsn, int $timeout, int $connectTimeout): OpenAiClient
    {
        if ($dsn === null) {
            return new OpenAiClient(timeout: $timeout, connectTimeout: $connectTimeout);
        }

        $prefix = 'openai:';
        if (!str_starts_with($dsn, $prefix)) {
            throw new InvalidArgumentException('Unsupported AI client DSN. Expected "openai:<apikey>".');
        }

        return new OpenAiClient(
            apiKey: substr($dsn, strlen($prefix)),
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );
    }

    /**
     * Normalizes strings, prompt instances and tool instances to values accepted by `PhoreAi::with()`.
     *
     * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
     * @return list<PromptType|ToolType>
     */
    public static function normalizePromptItems(string|PromptType|ToolType|array $prompts): array
    {
        $items = is_array($prompts) ? $prompts : [$prompts];
        $normalized = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $normalized[] = new TextPrompt($item);
                continue;
            }

            if ($item instanceof PromptType || $item instanceof ToolType) {
                $normalized[] = $item;
                continue;
            }

            throw new InvalidArgumentException('Expected string, PromptType or ToolType.');
        }

        return $normalized;
    }

    /**
     * Checks whether a normalized item list already contains a tool of the requested class.
     *
     * @param list<PromptType|ToolType> $items
     * @param class-string<ToolType> $className
     */
    public static function hasTool(array $items, string $className): bool
    {
        foreach ($items as $item) {
            if ($item instanceof $className) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps an OpenAI image output format option to a content type.
     */
    public static function contentTypeFromImageOutputFormat(string $outputFormat): string
    {
        return match ($outputFormat) {
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }
}
