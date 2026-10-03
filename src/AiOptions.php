<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use InvalidArgumentException;
use Phore\AiHarness\Client\OpenAiClient;
use Phore\AiHarness\Logging\LoggerInterface;

/**
 * Typed defaults shared by AI context operations.
 *
 * The object represents only common provider/runtime options. Operation-specific
 * settings such as input, output_class or image options remain per-call arrays.
 *
 * @see AiContext
 * @see Helper\Toolkit::createAi()
 */
final readonly class AiOptions
{
    public const KEYS = [
        'client',
        'model',
        'reasoning',
        'timeout',
        'connect_timeout',
        'debug_log',
    ];

    /**
     * @param array<string, mixed>|null $reasoning Responses API reasoning config.
     * @throws InvalidArgumentException For non-positive timeout values.
     * @example $options = new AiOptions(model: 'gpt-5-mini', debugLog: true);
     * @see fromArray()
     */
    public function __construct(
        public OpenAiClient|string|null $client = null,
        public ?string $model = null,
        public ?array $reasoning = ['effort' => 'low'],
        public ?int $timeout = null,
        public ?int $connectTimeout = null,
        public bool|LoggerInterface $debugLog = false,
    ) {
        if ($timeout !== null && $timeout <= 0) {
            throw new InvalidArgumentException('timeout must be a positive integer.');
        }
        if ($connectTimeout !== null && $connectTimeout <= 0) {
            throw new InvalidArgumentException('connect_timeout must be a positive integer.');
        }
    }

    /**
     * Normalize an options array or pass an existing instance through unchanged.
     *
     * Unknown keys fail immediately so misspelled context defaults cannot be
     * silently ignored. Array keys use the existing helper API names.
     *
     * @param array<string, mixed>|self $options
     * @throws InvalidArgumentException For unknown keys or invalid value types.
     * @example $options = AiOptions::fromArray(['model' => 'gpt-5-mini']);
     * @example $same = AiOptions::fromArray($options);
     * @see toArray()
     */
    public static function fromArray(array|self $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        $unknown = array_values(array_diff(array_keys($options), self::KEYS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown AI option(s): ' . implode(', ', $unknown));
        }

        $client = $options['client'] ?? null;
        if (!$client instanceof OpenAiClient && !is_string($client) && $client !== null) {
            throw new InvalidArgumentException('client must be an OpenAiClient, DSN string or null.');
        }

        $model = $options['model'] ?? null;
        if ($model !== null && !is_string($model)) {
            throw new InvalidArgumentException('model must be a string or null.');
        }

        $reasoning = array_key_exists('reasoning', $options)
            ? $options['reasoning']
            : ['effort' => 'low'];
        if ($reasoning !== null && !is_array($reasoning)) {
            throw new InvalidArgumentException('reasoning must be an array or null.');
        }

        $timeout = $options['timeout'] ?? null;
        if ($timeout !== null && !is_int($timeout)) {
            throw new InvalidArgumentException('timeout must be an integer or null.');
        }

        $connectTimeout = $options['connect_timeout'] ?? null;
        if ($connectTimeout !== null && !is_int($connectTimeout)) {
            throw new InvalidArgumentException('connect_timeout must be an integer or null.');
        }

        $debugLog = $options['debug_log'] ?? false;
        if (!is_bool($debugLog) && !$debugLog instanceof LoggerInterface) {
            throw new InvalidArgumentException('debug_log must be a boolean or LoggerInterface.');
        }

        return new self(
            client: $client,
            model: $model,
            reasoning: $reasoning,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            debugLog: $debugLog,
        );
    }

    /**
     * Convert to the existing common helper option names.
     *
     * @return array{client: OpenAiClient|string|null, model: ?string, reasoning: array<string, mixed>|null, timeout: ?int, connect_timeout: ?int, debug_log: bool|LoggerInterface}
     * @example $array = $options->toArray();
     * @see fromArray()
     */
    public function toArray(): array
    {
        return [
            'client' => $this->client,
            'model' => $this->model,
            'reasoning' => $this->reasoning,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'debug_log' => $this->debugLog,
        ];
    }
}
