<?php

declare(strict_types=1);

namespace Phore\AiHarness\Interactive;

use InvalidArgumentException;
use RuntimeException;

/** Versioned authenticated encryption for self-contained continuation keys. */
final readonly class ContinueKeyGenerator
{
    private const PREFIX = 'v1.';
    private const MAX_TOKEN_BYTES = 33554432;

    /** @param string $key Exactly 32 binary bytes, derived by Keystore. */
    public function __construct(
        #[\SensitiveParameter] private string $key,
        private string $scope,
    ) {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Interactive mode requires ext-sodium.');
        }
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('Invalid continuation encryption key.');
        }
    }

    /**
     * Encrypt JSON state with a fresh random nonce. Invalid UTF-8 fails closed.
     * @example $token = $generator->encode($key);
     * @see decode()
     */
    public function encode(ContinueKey $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $json = json_encode([
            'responseId' => $key->responseId,
            'contract' => $key->contract,
            'state' => $key->state,
        ], JSON_THROW_ON_ERROR);
        $encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $json, self::PREFIX . $this->scope, $nonce, $this->key,
        );
        $token = self::PREFIX . sodium_bin2base64($nonce . $encrypted, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new RuntimeException('Interactive continuation exceeds the token size limit.');
        }

        return $token;
    }

    /**
     * Authenticate and decode before provider requests or callback execution.
     * @throws InvalidArgumentException For malformed, tampered or wrong-scope tokens.
     * @example $key = $generator->decode($token);
     * @see encode()
     */
    public function decode(#[\SensitiveParameter] string $token): ContinueKey
    {
        try {
            if (!str_starts_with($token, self::PREFIX) || strlen($token) > self::MAX_TOKEN_BYTES) {
                throw new InvalidArgumentException('Invalid continuation token.');
            }
            $bytes = sodium_base642bin(substr($token, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($bytes) < $size + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
                throw new InvalidArgumentException('Invalid continuation token.');
            }
            $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($bytes, $size), self::PREFIX . $this->scope, substr($bytes, 0, $size), $this->key,
            );
            if ($json === false) {
                throw new InvalidArgumentException('Invalid continuation token.');
            }
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['responseId'] ?? null) || $data['responseId'] === ''
                || !is_string($data['contract'] ?? null) || !is_array($data['state'] ?? null)) {
                throw new InvalidArgumentException('Invalid continuation token.');
            }

            return new ContinueKey($data['responseId'], $data['contract'], $data['state']);
        } catch (\SodiumException|\JsonException) {
            throw new InvalidArgumentException('Invalid continuation token.');
        }
    }
}
