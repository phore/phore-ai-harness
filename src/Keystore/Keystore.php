<?php

declare(strict_types=1);

namespace Phore\AiHarness\Keystore;

use InvalidArgumentException;
use RuntimeException;

final class Keystore
{
    private const DEFAULT_SECRET_DIR = '/var/run/secrets';

    private static ?self $instance = null;

    /**
     * @var array<string, string>
     */
    private array $keys = [];

    /**
     * @var array<string, true>
     */
    private array $loadedProviders = [];

    private function __construct(
        private readonly string $secretDir = self::DEFAULT_SECRET_DIR,
    ) {
        $this->loadProvider('open_ai');
    }

    public static function instance(): self
    {
        self::$instance ??= new self();

        return self::$instance;
    }

    /**
     * @internal Intended for tests only.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function addKey(string $key, string $provider = 'open_ai'): self
    {
        $provider = $this->normalizeProvider($provider);
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('API key must not be empty.');
        }

        $this->keys[$provider] = $key;

        return $this;
    }

    public function hasKey(string $provider = 'open_ai'): bool
    {
        $provider = $this->normalizeProvider($provider);
        $this->loadProvider($provider);

        return isset($this->keys[$provider]);
    }

    public function getKey(string $provider = 'open_ai'): string
    {
        $provider = $this->normalizeProvider($provider);
        $this->loadProvider($provider);

        if (!isset($this->keys[$provider])) {
            throw new RuntimeException('No API key configured for provider "' . $provider . '".');
        }

        return $this->keys[$provider];
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->keys;
    }

    private function loadProvider(string $provider): void
    {
        if (isset($this->loadedProviders[$provider])) {
            return;
        }

        $this->loadedProviders[$provider] = true;

        $key = $this->readEnvKey($provider) ?? $this->readSecretKey($provider);
        if ($key !== null) {
            $this->addKey($key, $provider);
        }
    }

    private function readEnvKey(string $provider): ?string
    {
        foreach ($this->envNames($provider) as $envName) {
            $value = getenv($envName);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function readSecretKey(string $provider): ?string
    {
        $path = $this->secretDir . '/' . $provider;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $value = file_get_contents($path);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function envNames(string $provider): array
    {
        $upperProvider = strtoupper($provider);
        $names = [
            $upperProvider . '_API_KEY',
            $upperProvider . '_KEY',
            $upperProvider,
        ];

        if ($provider === 'open_ai') {
            array_unshift($names, 'OPENAI_API_KEY');
        }

        return array_values(array_unique($names));
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $provider = preg_replace('/[^a-z0-9]+/', '_', $provider) ?? '';
        $provider = trim($provider, '_');

        if ($provider === '') {
            throw new InvalidArgumentException('Provider must not be empty.');
        }

        return $provider;
    }
}
