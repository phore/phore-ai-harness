<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use InvalidArgumentException;

abstract readonly class AbstractToolType implements ToolType
{
    /**
     * @var array<string, string>
     */
    protected const PROVIDER_TYPES = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public array $options = [],
    ) {
    }

    public function available(string $provider = 'open_ai'): bool
    {
        return isset(static::PROVIDER_TYPES[$this->normalizeProvider($provider)]);
    }

    public function type(string $provider = 'open_ai'): string
    {
        $provider = $this->normalizeProvider($provider);

        if (!$this->available($provider)) {
            throw new InvalidArgumentException('Tool ' . static::class . ' is not available for provider "' . $provider . '".');
        }

        return static::PROVIDER_TYPES[$provider];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $provider = 'open_ai'): array
    {
        return array_replace(['type' => $this->type($provider)], $this->options);
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
