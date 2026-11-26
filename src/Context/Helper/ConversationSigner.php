<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context\Helper;

use InvalidArgumentException;
use JsonException;
use Phore\AiHarness\ResumeStateException;
use SensitiveParameter;

/** @internal Authenticates portable conversation cursors without storing sessions. */
final readonly class ConversationSigner
{
    private const PREFIX = 'aih1';
    private string $key;

    public function __construct(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] array $binding,
        private ?int $ttlSeconds = 3600,
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('AI conversation signing key must not be empty.');
        }
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            throw new InvalidArgumentException('AI conversation token lifetime must be positive or null.');
        }

        // Beliebig formatierte Secrets auf einen festen Schlüssel abbilden.
        $masterKey = hash('sha256', $secret, true);
        $this->validateBinding($binding, 0);
        try {
            $encoded = json_encode($binding, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('AI conversation signing data cannot be serialized as JSON.', 0, $error);
        }

        // Bindungsdaten bleiben serverseitig und trennen die Schlüssel je Scope.
        $this->key = hash_hmac('sha256', "phore-ai-harness:conversation:v1\0" . $encoded, $masterKey, true);
    }

    public static function isSigned(string $token): bool
    {
        return str_starts_with($token, self::PREFIX . '.');
    }

    public function sign(string $payload, string $purpose): string
    {
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $body = self::PREFIX . '.' . $purpose . '.' . time() . '.' . $encoded;

        return $body . '.' . hash_hmac('sha256', $body, $this->key);
    }

    public function verify(string $token, string $purpose): string
    {
        $parts = explode('.', $token, 5);
        if (count($parts) !== 5
            || $parts[0] !== self::PREFIX
            || $parts[1] !== $purpose
            || preg_match('/^[1-9][0-9]{0,17}$/D', $parts[2]) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[3]) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $parts[4]) !== 1
        ) {
            throw new ResumeStateException('Invalid or unsigned AI conversation token.');
        }

        // Erst die Signatur prüfen; danach Zeitstempel und Payload verwenden.
        $body = implode('.', array_slice($parts, 0, 4));
        if (!hash_equals(hash_hmac('sha256', $body, $this->key), $parts[4])) {
            throw new ResumeStateException('Invalid AI conversation token signature or scope.');
        }
        $issuedAt = (int) $parts[2];
        $now = time();
        if ($issuedAt > $now || ($this->ttlSeconds !== null && $now - $issuedAt >= $this->ttlSeconds)) {
            throw new ResumeStateException('AI conversation token has expired or has an invalid issue time.');
        }

        $decoded = base64_decode(strtr($parts[3], '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            throw new ResumeStateException('Invalid AI conversation token payload.');
        }

        return $decoded;
    }

    private function validateBinding(#[SensitiveParameter] mixed $value, int $depth): void
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('AI conversation signing data is recursive or too deeply nested.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateBinding($item, $depth + 1);
            }
        } elseif ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('AI conversation signing data must contain only JSON-compatible scalars and arrays.');
        }
    }
}
